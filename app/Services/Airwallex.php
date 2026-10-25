<?php

namespace App\Services;

use App\Models\AirwallexEntry;
use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\Payout;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Airwallex, read-only: where Stripe pays out, and the card Meta charges for the client's ads.
 *  - A deposit is matched to the Stripe payout it is ("arrived").
 *  - A Meta charge on the card is booked to the client whose ads it paid for.
 *  - Warns when the balance won't cover the next week of Meta, or a payout is late.
 */
class Airwallex
{
    public const BASE = 'https://api.airwallex.com/api/v1';

    /** Warn when the balance covers fewer days of Meta spend than this. */
    public const COVER_DAYS = 7;

    /** A payout not in Airwallex this many days after Stripe's arrival date is late. */
    public const LATE_DAYS = 3;

    /** Bringing in older history: store it, but don't notify about each old movement. */
    private bool $quiet = false;

    public function __construct(private Notify $notify) {}

    public static function configured(): bool
    {
        return filled(Setting::get('airwallex_client_id')) && filled(Setting::get('airwallex_api_key'));
    }

    private function token(): string
    {
        return Cache::remember('airwallex-token:'.md5((string) Setting::get('airwallex_client_id')), now()->addMinutes(25), function () {
            $res = Http::timeout(30)->acceptJson()->withHeaders([
                'x-client-id' => Setting::get('airwallex_client_id'),
                'x-api-key' => Setting::get('airwallex_api_key'),
            ])->post(self::BASE.'/authentication/login');

            if ($res->successful() && filled($res->json('token'))) {
                return $res->json('token');
            }
            // A key made in Airwallex's demo works only there: say so instead of a bare "Forbidden".
            $demo = Http::timeout(15)->acceptJson()->withHeaders([
                'x-client-id' => Setting::get('airwallex_client_id'),
                'x-api-key' => Setting::get('airwallex_api_key'),
            ])->post('https://api-demo.airwallex.com/api/v1/authentication/login');
            if ($demo->successful() && filled($demo->json('token'))) {
                throw new RuntimeException('Airwallex: this is a demo (sandbox) key — create the key in the live Airwallex account.');
            }

            throw new RuntimeException('Airwallex: '.($res->json('message') ?? 'login refused ('.$res->status().')').' — check the client ID, the API key, and that the key allows the server IP 77.37.34.204.');
        });
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $params = []): array
    {
        $res = Http::timeout(30)->retry(2, 500, throw: false)->acceptJson()->withToken($this->token())->get(self::BASE.'/'.ltrim($path, '/'), $params);
        if ($res->failed()) {
            throw new RuntimeException('Airwallex: '.($res->json('message') ?? 'request failed ('.$res->status().')'));
        }

        return $res->json() ?? [];
    }

    /** Every page of a list. @return array<int, array<string, mixed>> */
    private function all(string $path, array $params): array
    {
        $items = [];
        for ($page = 0; $page < 20; $page++) {
            $res = $this->get($path, $params + ['page_num' => $page, 'page_size' => 100]);
            $items = [...$items, ...($res['items'] ?? [])];
            if (empty($res['has_more'])) {
                break;
            }
        }

        return $items;
    }

    /** Available money per currency, in cents — only currencies that hold something: ['SGD' => 20352, …]. */
    public function balances(): array
    {
        return collect($this->get('balances/current'))->filter(fn ($b) => is_array($b) && isset($b['currency']))
            ->mapWithKeys(fn ($b) => [strtoupper($b['currency']) => self::cents($b['available_amount'] ?? 0)])
            ->filter(fn (int $cents) => $cents !== 0)->all();
    }

    /** Hourly from the heartbeat (every 15 min at most). */
    public function run(bool $force = false, int $days = 14): array
    {
        if (! self::configured() || (! $force && ! Cache::add('airwallex-run', 1, now()->addMinutes(14)))) {
            return ['skipped' => true];
        }

        try {
            $new = $this->sync($days);
            $balances = $this->balances();
            Setting::put('airwallex_balances', json_encode($balances));
            Setting::put('airwallex_synced_at', now()->toIso8601String());
            Setting::put('airwallex_error', null);
            $this->checks($balances);

            return ['new' => $new];
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 300);
            if (Setting::get('airwallex_error') !== $message) {
                $this->notify->send('billing', '⚠️ Airwallex not read', $message, route('payouts.index'), 'klok-airwallex');
            }
            Setting::put('airwallex_error', $message);

            return ['error' => $message];
        }
    }

    /** Pull the last two weeks: deposits and fees from the ledger, card charges from Issuing. Returns new entries. */
    public function sync(int $days = 14): int
    {
        $this->quiet = $days > 14;
        $from = now()->subDays($days)->utc()->format('Y-m-d\TH:i:sP');
        $new = 0;

        foreach ($this->all('financial_transactions', ['from_created_at' => $from]) as $t) {
            $kind = self::kindOf($t);
            if ($kind === 'card') {
                continue; // card charges come from Issuing, with the merchant's name
            }
            $new += (int) $this->keep('ft:'.$t['id'], $kind, self::cents($t['amount'] ?? 0), $t['currency'] ?? '', $t['description'] ?? ($t['transaction_type'] ?? null), $t['status'] ?? null, $t['created_at'] ?? null, $t);
        }

        try {
            $card = $this->all('issuing/transactions', ['from_created_at' => $from]);
        } catch (Throwable) {
            $card = []; // Issuing not on this account (or no permission): deposits still work
        }
        foreach ($card as $t) {
            $type = strtoupper($t['transaction_type'] ?? '');
            if (! in_array($type, ['CLEARING', 'REFUND'], true)) {
                continue; // an authorisation is followed by its clearing; count each charge once
            }
            $merchant = trim(($t['merchant']['name'] ?? '').' '.($t['merchant']['city'] ?? ''));
            $amount = self::cents(abs((float) ($t['billing_amount'] ?? $t['transaction_amount'] ?? 0)));
            $new += (int) $this->keep('it:'.($t['transaction_id'] ?? $t['id']), self::isMeta($merchant) ? 'meta' : 'card',
                $type === 'REFUND' ? $amount : -$amount, $t['billing_currency'] ?? $t['transaction_currency'] ?? '', $merchant ?: null,
                $t['status'] ?? $type, $t['posted_date'] ?? $t['transaction_date'] ?? null, $t);
        }

        return $new;
    }

    /** Store one movement; tell about it the first time it is seen. */
    private function keep(string $id, string $kind, int $amount, string $currency, ?string $description, ?string $status, ?string $at, array $raw): bool
    {
        $entry = AirwallexEntry::firstOrNew(['airwallex_id' => $id]);
        $isNew = ! $entry->exists;
        $entry->fill([
            'kind' => $kind, 'amount' => $amount, 'currency' => strtoupper($currency), 'description' => $description ? mb_substr($description, 0, 250) : null,
            'status' => $status, 'occurred_at' => $at ? CarbonImmutable::parse($at) : now(), 'raw' => $raw,
        ]);
        if ($kind === 'meta' && ! $entry->client_id) {
            $entry->client_id = self::metaClient()?->id;
        }
        $entry->save();

        if ($isNew && ! $this->quiet) {
            $kind === 'deposit' && $amount > 0 ? $this->arrived($entry) : $this->tellEntry($entry);
        }

        return $isNew;
    }

    /** A deposit: which Stripe payout is it? */
    private function arrived(AirwallexEntry $entry): void
    {
        $payout = Payout::whereRaw('upper(currency) = ?', [$entry->currency])->whereIn('status', ['paid', 'in_transit'])
            ->whereDoesntHave('airwallexEntries')
            ->whereBetween('arrival_at', [$entry->occurred_at->copy()->subDays(5), $entry->occurred_at->copy()->addDays(2)])
            ->get()->first(fn (Payout $p) => abs($p->amount - $entry->amount) <= max(100, (int) round($p->amount * 0.01)));

        if ($payout) {
            $entry->update(['payout_id' => $payout->id]);
            $this->notify->send('billing', '🏦 Stripe payout arrived in Airwallex',
                Notify::money($entry->amount, $entry->currency).' landed (Stripe sent '.Notify::money($payout->amount, $entry->currency).' on '.$payout->occurred_at?->format('j M').').'.$this->balanceLine($entry->currency),
                route('payouts.index'), 'klok-airwallex');

            return;
        }
        $this->tellEntry($entry);
    }

    private function tellEntry(AirwallexEntry $entry): void
    {
        $money = Notify::money(abs($entry->amount), $entry->currency);
        [$title, $body] = match ($entry->kind) {
            'meta' => ['💳 Meta charged the Airwallex card '.$money, ($entry->client ? 'For '.($entry->client->company ?: $entry->client->name).'\'s ads. ' : '').$this->spendLine($entry).$this->balanceLine($entry->currency)],
            'deposit' => ['🏦 Money in Airwallex: '.$money, ($entry->description ?: 'Deposit').$this->balanceLine($entry->currency)],
            'card' => ['💳 Airwallex card: '.$money, ($entry->description ?: 'Card payment').$this->balanceLine($entry->currency)],
            'payout' => ['↗️ Sent from Airwallex: '.$money, ($entry->description ?: 'Transfer').$this->balanceLine($entry->currency)],
            'fee' => ['Airwallex fee '.$money, $entry->description],
            'conversion' => [($entry->amount >= 0 ? '💱 Converted into ' : '💱 Converted out of ').$entry->currency.': '.$money, 'Currency conversion in Airwallex.'.$this->balanceLine($entry->currency)],
            default => [($entry->amount >= 0 ? '⬇️ Airwallex +' : '⬆️ Airwallex −').$money, $entry->description],
        };
        $this->notify->send('billing', $title, $body, route('payouts.index'), 'klok-airwallex');
    }

    /** Meta's charges so far this month next to what the ad account spent. */
    private function spendLine(AirwallexEntry $entry): string
    {
        if (! $entry->client_id) {
            return '';
        }
        $month = $entry->occurred_at->copy()->startOfMonth();
        $charged = -(int) AirwallexEntry::where('kind', 'meta')->where('client_id', $entry->client_id)->where('occurred_at', '>=', $month)->sum('amount');
        $spent = (int) MetaSpendDay::where('client_id', $entry->client_id)->where('date', '>=', $month->toDateString())->sum('spend');

        return 'This month Meta charged '.Notify::money($charged, $entry->currency).' for '.Notify::money($spent, $entry->currency).' of spend.';
    }

    private function balanceLine(string $currency): string
    {
        $balances = json_decode((string) Setting::get('airwallex_balances'), true) ?: [];

        return isset($balances[$currency]) ? ' Airwallex '.$currency.' balance: '.Notify::money($balances[$currency], $currency).'.' : '';
    }

    /** Enough for the next week of Meta? Any payout late? Once a day each. */
    private function checks(array $balances): void
    {
        $client = self::metaClient();
        if ($client) {
            $currency = strtoupper($client->currency ?: 'SGD');
            $daily = (int) round(MetaSpendDay::where('client_id', $client->id)
                ->where('date', '>=', CarbonImmutable::today()->subDays(7)->toDateString())->where('date', '<', CarbonImmutable::today()->toDateString())
                ->sum('spend') / 7);
            $need = $daily * self::COVER_DAYS;
            $have = $balances[$currency] ?? 0;
            if ($daily > 0 && $have < $need) {
                $coming = (int) Payout::whereRaw('upper(currency) = ?', [$currency])->whereIn('status', ['pending', 'in_transit'])->sum('amount');
                $this->notify->once('airwallex-low:'.now()->toDateString(), 'billing', '⚠️ Airwallex low for Meta',
                    'Airwallex has '.Notify::money($have, $currency).' — '.($client->company ?: $client->name).'\'s ads need about '.Notify::money($need, $currency).' for the next '.self::COVER_DAYS.' days.'
                    .($coming > 0 ? ' Stripe has '.Notify::money($coming, $currency).' on the way.' : ' Nothing on the way from Stripe — top up the client or move money in.'),
                    route('payouts.index'));
            }
        }

        if ($client) {
            $this->checkMetaCharges($client);
        }

        foreach (Payout::whereIn('status', ['paid', 'in_transit'])->whereDoesntHave('airwallexEntries')
            ->whereBetween('arrival_at', [now()->subDays(20), now()->subDays(self::LATE_DAYS)])->get() as $payout) {
            $this->notify->once('payout-late:'.$payout->id, 'billing', '⏳ Stripe payout not in Airwallex yet',
                Notify::money($payout->amount, strtoupper($payout->currency)).' was due '.$payout->arrival_at->format('j M').' — check Stripe and Airwallex.', route('payouts.index'));
        }
    }

    /**
     * Meta bills after the spend — at its threshold or its monthly billing date, sometimes weeks
     * later — so each charge should be no more than what the ads spent since Meta's previous charge.
     * More than that (5% or S$5 of slack), and something is off: a wrong charge, or the card paying
     * for another ad account. Told once per charge; charges of the last 30 days are checked.
     */
    public function checkMetaCharges(Client $client): void
    {
        $charges = AirwallexEntry::where('kind', 'meta')->where('client_id', $client->id)->where('amount', '<', 0)->orderBy('occurred_at')->get();
        $currency = strtoupper($client->currency ?: 'SGD');
        foreach ($charges as $i => $charge) {
            if ($charge->occurred_at->lt(now()->subDays(30))) {
                continue;
            }
            $since = $i > 0 ? $charges[$i - 1]->occurred_at->toDateString() : $charge->occurred_at->copy()->subDays(60)->toDateString();
            $spent = (int) MetaSpendDay::where('client_id', $client->id)->where('date', '>=', $since)->where('date', '<=', $charge->occurred_at->toDateString())->sum('spend');
            $amount = -$charge->amount;
            if ($amount <= $spent + max(500, (int) round($spent * 0.05))) {
                continue;
            }
            $this->notify->once('meta-mismatch:'.$charge->id, 'billing', '🔎 Meta charged more than the ads spent',
                'On '.$charge->occurred_at->format('j M').' Meta charged the Airwallex card '.Notify::money($amount, $currency).', but '.($client->company ?: $client->name)."'s ads spent only "
                .Notify::money($spent, $currency).' since '.CarbonImmutable::parse($since)->format('j M').". Check Meta's billing (Payment activity) and which ad account uses this card.",
                route('ad-money.index', ['client' => $client->id, 'period' => 'last30']));
        }
    }

    /** Whose ads the Airwallex card pays for: the one prepaid Meta client (Tasfia), unless set. */
    public static function metaClient(): ?Client
    {
        if ($id = Setting::get('airwallex_meta_client_id')) {
            return Client::find($id);
        }

        return Client::whereNotNull('meta_ad_account_id')->where('billing_mode', 'prepaid')->orderBy('id')->first();
    }

    /** @param  array<string, mixed>  $t */
    public static function kindOf(array $t): string
    {
        $type = strtoupper(($t['transaction_type'] ?? '').' '.($t['source_type'] ?? ''));

        return match (true) {
            str_contains($type, 'DEPOSIT') => 'deposit',
            str_contains($type, 'CONVERSION') => 'conversion',
            str_contains($type, 'ISSUING') || str_contains($type, 'CARD') => 'card',
            str_contains($type, 'FEE') => 'fee',
            str_contains($type, 'PAYOUT') || str_contains($type, 'TRANSFER') => 'payout',
            default => 'other',
        };
    }

    public static function isMeta(string $merchant): bool
    {
        return (bool) preg_match('/\b(FACEBK|FACEBOOK|META ?PLATFORMS|META ADS)\b/i', $merchant);
    }

    public static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
