<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AdAccountState;
use App\Models\MoneyTransaction;
use App\Models\PersonalAdAssignment;
use App\Models\PersonalAdBill;
use App\Models\PersonalAdCharge;
use App\Models\PersonalAdDay;
use App\Models\PersonalAdPayment;
use App\Models\PersonalClient;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * My own clients' ads (Edge Education, Marine Orvion…), each in its ad account's own currency:
 * which campaigns are theirs, what they spent and brought, campaign changes and stops, a note each
 * day and each Monday, and — for what my card paid — a monthly ads bill drafted in My invoices.
 */
class PersonalAds
{
    /** On first sync, bring in this many days before the assignment's start at most. */
    private const FIRST_DAYS = 90;

    public function __construct(private MetaAds $meta, private Notify $notify) {}

    /** Whose is this campaign? Its own assignment first, else the account's. */
    public static function owner(Collection $assignments, string $account, string $campaign): ?PersonalAdAssignment
    {
        $mine = $assignments->where('account_id', $account);

        return $mine->firstWhere('campaign_id', $campaign) ?? $mine->first(fn ($a) => $a->campaign_id === null);
    }

    public static function token(PersonalClient $client): string
    {
        if (! $client->ad_report_token) {
            $client->update(['ad_report_token' => Str::random(32)]);
        }

        return $client->ad_report_token;
    }

    /** Pull recent days (or, the first time, everything since each assignment started) and the campaigns' state. */
    public function sync(bool $force = false): int
    {
        $assignments = PersonalAdAssignment::all();
        if ($assignments->isEmpty() || ! MetaAds::configured() || (! $force && ! Cache::add('personal-ads-sync', 1, now()->addMinutes(55)))) {
            return 0;
        }
        $today = CarbonImmutable::today();
        $rows = 0;

        foreach ($assignments->groupBy('account_id') as $account => $list) {
            try {
                $have = PersonalAdDay::where('account_id', $account)->exists();
                $from = $have ? $today->subDays(3) : CarbonImmutable::parse($list->min('from_date'))->max($today->subDays(self::FIRST_DAYS));
                foreach ($this->meta->dailyCampaignSpend($account, $from, $today) as $r) {
                    $owner = self::owner($assignments, $account, (string) $r['campaign_id']);
                    if (! $owner || $owner->from_date->gt(CarbonImmutable::parse($r['date']))) {
                        continue;
                    }
                    PersonalAdDay::updateOrCreate(['account_id' => $account, 'campaign_id' => (string) $r['campaign_id'], 'date' => $r['date']], [
                        'personal_client_id' => $owner->personal_client_id, 'campaign_name' => $r['campaign_name'], 'currency' => $owner->currency,
                        'spend' => $r['spend'], 'impressions' => $r['impressions'] ?? 0, 'clicks' => $r['clicks'] ?? 0, 'reach' => $r['reach'] ?? 0,
                        'results' => $r['results'] ?? 0, 'result_type' => $r['result_type'] ?? null, 'paid_by' => $owner->paid_by,
                    ]);
                    $rows++;
                }
                $this->watch($assignments, $account);
                $this->watchBilling($assignments, $account);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $rows;
    }

    /**
     * Meta's card charges for an account my card pays for one client: read from Meta's activity log
     * (exact amount, day and Meta's transaction id), each added once to their charges — those up to
     * their "settled" day are skipped. Also keeps what is spent but not charged yet, to show.
     */
    private function watchBilling(Collection $assignments, string $account): void
    {
        $mine = $assignments->where('account_id', $account)->where('paid_by', 'me');
        $owners = $mine->pluck('personal_client_id')->unique();
        if ($owners->count() !== 1) {
            return; // their own card, or shared between clients (enter charges by hand)
        }
        $now = $this->meta->billing($account);
        AdAccountState::updateOrCreate(['account_id' => $account], [
            'currency' => $now['currency'], 'amount_spent' => $now['amount_spent'], 'unbilled' => $now['unbilled'], 'card' => $now['card'], 'checked_at' => now(),
        ]);

        $client = PersonalClient::find($owners->first());
        $since = CarbonImmutable::parse($mine->min('from_date'));
        if ($client->ad_billed_until) {
            $since = $since->max(CarbonImmutable::parse($client->ad_billed_until)->addDay());
        }
        $tz = PersonalReminders::timezone();
        foreach ($this->meta->charges($account, $since->max(CarbonImmutable::today()->subDays(90))) as $c) {
            $day = CarbonImmutable::parse($c['time'])->setTimezone($tz)->startOfDay();
            // Compared as calendar days (Meta's time in Dhaka against the settled day), never across time zones.
            if ($day->toDateString() < $since->toDateString() || $c['amount'] <= 0 || PersonalAdCharge::where('meta_id', $c['id'])->exists()) {
                continue;
            }
            $charge = PersonalAdCharge::create(['personal_client_id' => $client->id, 'date' => $day->toDateString(), 'currency' => $c['currency'], 'amount' => $c['amount'],
                'note' => 'Meta charged '.($now['card'] ?: 'the card'), 'source' => 'meta', 'meta_id' => $c['id']]);
            $this->bookCharge($charge);
            $this->notify->send('billing', "💳 Meta charged your card for {$client->name}: ".Notify::money($c['amount'], $c['currency']),
                $day->format('j M').($now['card'] ? " · {$now['card']}" : '').' · from Meta. It goes on their next ads bill.',
                route('ad-clients.show', ['client' => $client, 'tab' => 'money']), 'klok-adcharge-'.$client->id);
        }
    }

    /**
     * Meta's charge as my spending, once, in the account its card belongs to (in that account's money:
     * a taka charge on a dollar card at Meta's taka per dollar). $balanceAsIs: the charge was before the
     * balance I keep, so it is spending but the balance stays where it is.
     */
    public function bookCharge(PersonalAdCharge $charge, bool $balanceAsIs = false): ?MoneyTransaction
    {
        $client = PersonalClient::find($charge->personal_client_id);
        $account = $client?->ad_card_account_id ? Account::find($client->ad_card_account_id) : null;
        if (! $account || $charge->money_transaction_id || $charge->source !== 'meta') {
            return null;
        }
        $from = strtoupper($charge->currency);
        $to = strtoupper($account->currency);
        $amount = match (true) {
            $from === $to => $charge->amount,
            $from === 'BDT' && $to === 'USD' => (int) round($charge->amount / (float) $client->ad_meta_rate),
            $from === 'USD' && $to === 'BDT' => (int) round($charge->amount * (float) $client->ad_meta_rate),
            default => null,
        };
        if (! $amount) {
            return null;
        }
        // The card's own message booked it already: that entry is this charge.
        if (! $balanceAsIs && ($card = AutoEntries::bookedMetaCharge($account, $amount, $charge->date->toDateString(), fromMetaLog: false))
            && ! PersonalAdCharge::where('money_transaction_id', $card->id)->exists()) {
            $charge->update(['money_transaction_id' => $card->id]);

            return $card;
        }
        $tx = Ledger::save([
            'kind' => 'expense', 'account_id' => $account->id, 'amount' => $amount, 'category' => 'client_ads', 'counterparty' => 'Meta',
            'description' => ($client->company ?: $client->name).' ads · Meta charged '.Notify::money($charge->amount, $charge->currency),
            'occurred_on' => $charge->date->toDateString(),
        ]);
        if ($balanceAsIs) {
            $account->increment('opening_balance', $amount);
        }
        $charge->update(['money_transaction_id' => $tx->id]);

        return $tx;
    }

    /**
     * Money a client paid for their ads (any amount): it lands in my account once, then pays their
     * oldest ads bills; what is left is credit for the next bill.
     */
    public function receivePayment(PersonalClient $c, int $amount, string $date, ?int $accountId, ?string $note): PersonalAdPayment
    {
        $tx = $accountId ? Ledger::save([
            'kind' => 'income', 'account_id' => $accountId, 'amount' => $amount, 'category' => 'client_ads',
            'counterparty' => $c->company ?: $c->name, 'description' => 'Ads payment'.($note ? " · {$note}" : ''), 'occurred_on' => $date,
        ]) : null;
        $payment = PersonalAdPayment::create(['personal_client_id' => $c->id, 'date' => $date, 'amount' => $amount, 'account_id' => $accountId,
            'money_transaction_id' => $tx?->id, 'note' => $note]);
        $this->applyCredit($c);

        return $payment;
    }

    /** Their credit (paid, not on a bill yet), in taka. */
    public static function credit(PersonalClient $c): int
    {
        return (int) PersonalAdPayment::where('personal_client_id', $c->id)->get()->sum(fn ($p) => $p->amount - $p->applied);
    }

    /** Put their credit on their unpaid ads bills, oldest first (no new money is booked: it came in already). */
    public function applyCredit(PersonalClient $c): void
    {
        $payments = PersonalAdPayment::where('personal_client_id', $c->id)->whereColumn('applied', '<', 'amount')->orderBy('date')->orderBy('id')->get();
        $bills = PersonalAdBill::with('invoice')->where('personal_client_id', $c->id)->orderBy('period_from')->get()
            ->filter(fn ($b) => $b->invoice && in_array($b->invoice->status, ['unpaid', 'partial'], true));
        foreach ($bills as $bill) {
            foreach ($payments as $payment) {
                $due = $bill->invoice->fresh()->due();
                $left = $payment->amount - $payment->applied;
                if ($due <= 0) {
                    break;
                }
                if ($left <= 0) {
                    continue;
                }
                $put = min($due, $left);
                app(PersonalInvoicing::class)->recordPayment($bill->invoice, $put, $payment->date->toDateString(), 'other', 'Ads payment (from their payments)', null, 'ads');
                // Remember which bill it paid, to show with the payment.
                $payment->update(['applied' => $payment->applied + $put, 'allocations' => [...($payment->allocations ?? []), ['invoice_id' => $bill->invoice->id, 'amount' => $put]]]);
            }
        }
    }

    /** Each client's campaigns as they are now; a change (rejected, paused, running again) is told. */
    private function watch(Collection $assignments, string $account): void
    {
        $campaigns = $this->meta->campaigns($account);
        foreach ($assignments->where('account_id', $account)->pluck('personal_client_id')->unique() as $clientId) {
            $client = PersonalClient::find($clientId);
            $before = $client->ad_campaigns ?? [];
            $mine = collect($campaigns)->filter(fn ($c, $id) => self::owner($assignments, $account, (string) $id)?->personal_client_id === $client->id)
                ->map(fn ($c) => $c + ['account' => $account]);
            foreach ($mine as $id => $c) {
                $was = $before[$id]['status'] ?? null;
                if ($was && $was !== $c['status'] && $text = AdPerformance::STATUS_TEXT[$c['status']] ?? null) {
                    $this->notify->send('billing', "{$client->name}: “{$c['name']}” {$text}",
                        in_array($c['status'], ['DISAPPROVED', 'WITH_ISSUES'], true) ? 'Its ads are not running. Open it to see why.' : 'It was '.strtolower(str_replace('_', ' ', $was)).'.',
                        route('ad-clients.show', $client), 'klok-campaign-'.$id);
                }
            }
            // Keep what other accounts of this client know; replace this account's campaigns.
            $kept = collect($before)->reject(fn ($c) => ($c['account'] ?? null) === $account);
            $client->update(['ad_campaigns' => $kept->union($mine)->all()]);
        }
    }

    /**
     * Per campaign over a period: spend, results, cost per result, budget, end, status — per currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function table(PersonalClient $client, CarbonImmutable $start, CarbonImmutable $end, bool $all = false): array
    {
        // Meta keeps old campaigns "ACTIVE" for ever: by default only those that spent in the period,
        // or ran in the last 30 days and now have a problem. "Show all" lists every one (to switch one back on).
        $recent = PersonalAdDay::where('personal_client_id', $client->id)->where('date', '>=', CarbonImmutable::today()->subDays(30)->toDateString())
            ->where('spend', '>', 0)->distinct()->pluck('campaign_id')->map(fn ($id) => (string) $id)->all();
        $known = $client->ad_campaigns ?? [];
        $days = PersonalAdDay::where('personal_client_id', $client->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('campaign_id');
        $ids = collect($known)->keys()->map(fn ($k) => (string) $k)->merge($days->keys())->unique();

        return $ids->map(function ($id) use ($known, $days) {
            $rows = $days[$id] ?? collect();
            $k = $known[$id] ?? [];
            $spend = (int) $rows->sum('spend');
            $results = (int) $rows->sum('results');
            $impressions = (int) $rows->sum('impressions');
            $clicks = (int) $rows->sum('clicks');

            return [
                'id' => (string) $id,
                'account' => $k['account'] ?? $rows->first()?->account_id,
                'name' => $k['name'] ?? $rows->last()?->campaign_name,
                'status' => $k['status'] ?? null,
                'daily_budget' => $k['daily_budget'] ?? null,
                'start' => $k['start'] ?? null,
                'last_spend' => $rows->where('spend', '>', 0)->max('date')?->toDateString(),
                'end' => $k['end'] ?? null,
                'currency' => $rows->first()?->currency ?? PersonalAdAssignment::where('account_id', $k['account'] ?? '')->value('currency'),
                'spend' => $spend,
                ...AdPerformance::present($k['objective'] ?? null, $rows->whereNotNull('result_type')->last()?->result_type, $spend, $results, (int) $rows->sum('reach')),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            ];
        })->filter(fn ($c) => $all || $c['spend'] > 0 || (in_array($c['id'], $recent, true) && in_array($c['status'], ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_REVIEW', 'ACTIVE'], true)))
            ->sortBy([fn ($a, $b) => ($b['status'] === 'ACTIVE') <=> ($a['status'] === 'ACTIVE'), fn ($a, $b) => $b['spend'] <=> $a['spend']])->values()->all();
    }

    /**
     * Money for a period, per currency: what the ads cost, what my card paid, and what was billed.
     *
     * @return array<string, array{spend: int, my_card: int, results: int}>
     */
    public static function money(PersonalClient $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return PersonalAdDay::where('personal_client_id', $client->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->groupBy('currency')->map(fn ($rows) => [
                'spend' => (int) $rows->sum('spend'),
                'my_card' => (int) $rows->where('paid_by', 'me')->sum('spend'),
                'results' => (int) $rows->sum('results'),
            ])->all();
    }

    /** Heartbeat: sync, then yesterday's note, Monday's summary, and the 1st's bills (once each). */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $this->sync();
        $local = $now->setTimezone(PersonalReminders::timezone());
        if ($local->hour >= 9) {
            $this->daily($local);
            $this->weekly($local);
            $this->bills($local);
        }

        return 1;
    }

    /** One note for all my ad clients: what yesterday cost and brought. */
    public function daily(CarbonImmutable $local): void
    {
        $day = $local->startOfDay()->subDay();
        $lines = PersonalClient::whereHas('adAssignments')->get()->map(function (PersonalClient $c) use ($day) {
            $money = self::money($c, $day, $day);
            if (! $money) {
                return null;
            }
            $results = collect(self::table($c, $day, $day))->where('results', '>', 0)
                ->map(fn ($r) => $r['results'].' '.$r['result_label'])->implode(', ');

            return $c->name.': '.collect($money)->map(fn ($m, $cur) => Notify::money($m['spend'], $cur))->implode(' + ').($results ? " ({$results})" : '');
        })->filter();
        if ($lines->isEmpty()) {
            return;
        }
        $this->notify->once('personal-ads-day:'.$day->toDateString(), 'billing', '📊 Client ads · '.$day->format('j M'), $lines->implode("\n"), route('ad-clients.index'));
    }

    /** Monday: each client's week, with their report link to forward. */
    public function weekly(CarbonImmutable $local): void
    {
        if (! $local->isMonday()) {
            return;
        }
        $end = $local->startOfDay()->subDay();
        $start = $end->subDays(6);
        foreach (PersonalClient::whereHas('adAssignments')->get() as $c) {
            $money = self::money($c, $start, $end);
            if (! $money) {
                continue;
            }
            $best = collect(self::table($c, $start, $end))->where('results', '>', 0)->sortBy('cost_per_result')->first();
            $this->notify->once("personal-ads-week:{$c->id}:".$start->toDateString(), 'billing', "🗓 {$c->name} · week of ".$start->format('j M'),
                implode("\n", array_filter([
                    'Spent '.collect($money)->map(fn ($m, $cur) => Notify::money($m['spend'], $cur))->implode(' + ').' · '.collect($money)->sum('results').' results',
                    $best ? "Best: “{$best['name']}” — {$best['results']} {$best['result_label']} at ".Notify::money($best['cost_per_result'], $best['currency']).' each' : null,
                    'Their report: '.route('ad-report.show', self::token($c)),
                ])),
                route('ad-clients.show', $c));
        }
    }

    /**
     * On the 2nd–4th (Meta still corrects the last days for a day or two): everything my card paid
     * up to the end of last month and not billed yet, drafted in My invoices.
     */
    public function bills(CarbonImmutable $local): int
    {
        if ($local->day < 2 || $local->day > 4) {
            return 0;
        }
        $to = $local->startOfMonth()->subDay()->startOfDay();
        $made = 0;
        foreach (PersonalClient::where('ad_bill', true)->whereHas('adAssignments')->get() as $c) {
            $made += count($this->billUpTo($c, $to));
        }

        return $made;
    }

    /** The first day not billed yet: the day after the last bill (or the settled date), else the first ad day. */
    public static function unbilledFrom(PersonalClient $c): ?CarbonImmutable
    {
        if ($c->ad_billed_until) {
            return CarbonImmutable::parse($c->ad_billed_until)->addDay();
        }
        $first = PersonalAdDay::where('personal_client_id', $c->id)->min('date');

        return $first ? CarbonImmutable::parse($first) : null;
    }

    /**
     * Bill what is not billed yet, up to $to (finished days only): by spend — the days right after
     * the last bill — or, for a client billed by card charges, every charge not billed yet. One bill
     * in taka; each day or charge is billed once.
     *
     * @return array<int, PersonalAdBill>
     */
    public function billUpTo(PersonalClient $c, CarbonImmutable $to): array
    {
        // Only finished days: today's spend is still growing, and a billed day is never billed again.
        $to = $to->min(CarbonImmutable::yesterday());

        if ($c->ad_bill_basis === 'charges') {
            $charges = PersonalAdCharge::where('personal_client_id', $c->id)->whereNull('personal_ad_bill_id')->whereDate('date', '<=', $to->toDateString())->orderBy('date')->get();
            if ($charges->isEmpty()) {
                return [];
            }
            // A charge pays for what the ads spent since the charge before it: those days decide the campaigns on the bill.
            $first = CarbonImmutable::parse($charges->first()->date);
            $previous = $this->chargeBefore($c, $first);
            $spentFrom = $previous ? $previous->addDay() : $first->subDays(30);
            $bill = $this->draftBill($c, $first, CarbonImmutable::parse($charges->last()->date), 'charges',
                $charges->groupBy('currency')->map(fn ($list) => (int) $list->sum('amount'))->all(), $spentFrom);
            PersonalAdCharge::whereKey($charges->pluck('id'))->update(['personal_ad_bill_id' => $bill->id]);

            return [$bill];
        }

        $from = self::unbilledFrom($c);
        if (! $from || $from->gt($to)) {
            return [];
        }
        $amounts = collect(self::money($c, $from, $to))->map(fn ($m) => $m['my_card'])->filter()->all();
        $bills = $amounts ? [$this->draftBill($c, $from, $to, 'spend', $amounts)] : [];
        $c->update(['ad_billed_until' => $to->toDateString()]);

        return $bills;
    }

    /**
     * Taka for an amount in the ad account's currency: → dollars (BDT ÷ Meta's rate) → × the
     * agency's dollar rate (KlokOne). Commission is added on top, separately.
     *
     * @param  array<string, int>  $amounts  currency => cents
     * @return array{usd: int, bdt: int, fx: float, lines: array<int, string>}
     */
    public static function inTaka(PersonalClient $c, array $amounts, ?float $fx = null): array
    {
        $fx ??= KlokOneRate::fx();
        $usd = 0.0; // exact: rounded only at the end, so no poisha are lost on the way
        $lines = [];
        foreach ($amounts as $cur => $cents) {
            $dollars = strtoupper($cur) === 'USD' ? (float) $cents : $cents / (float) $c->ad_meta_rate;
            $usd += $dollars;
            $lines[] = strtoupper($cur) === 'USD'
                ? Notify::money($cents, 'USD')
                : Notify::money($cents, $cur).' ÷ '.rtrim(rtrim(number_format((float) $c->ad_meta_rate, 2), '0'), '.').' = '.Notify::money((int) round($dollars), 'USD');
        }

        return ['usd' => (int) round($usd), 'bdt' => (int) round($usd * $fx), 'fx' => $fx, 'lines' => $lines];
    }

    /** The day of Meta's charge before this one — on record, or on Meta (an older, settled one isn't kept here). */
    private function chargeBefore(PersonalClient $c, CarbonImmutable $first): ?CarbonImmutable
    {
        $days = collect([PersonalAdCharge::where('personal_client_id', $c->id)->whereDate('date', '<', $first->toDateString())->max('date')]);
        foreach ($c->adAssignments()->where('paid_by', 'me')->pluck('account_id')->unique() as $account) {
            try {
                $tz = PersonalReminders::timezone();
                foreach ($this->meta->charges($account, $first->subDays(60)) as $ch) {
                    $day = CarbonImmutable::parse($ch['time'])->setTimezone($tz)->toDateString();
                    if ($day < $first->toDateString()) {
                        $days->push($day);
                    }
                }
            } catch (Throwable) {
                // Meta away: what is on record
            }
        }
        $last = $days->filter()->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->max();

        return $last ? CarbonImmutable::parse($last) : null;
    }

    /**
     * The bill's lines: each campaign that spent in those days, with its share of the total — what
     * the client sees. Never my rate, the dollars or my commission.
     *
     * @return array<int, array{description: string, qty: int, rate: int}>
     */
    private static function campaignLines(PersonalClient $c, CarbonImmutable $from, CarbonImmutable $to, int $total, string $period): array
    {
        $spent = PersonalAdDay::where('personal_client_id', $c->id)->where('paid_by', 'me')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get()->groupBy('campaign_name')->map(fn ($days) => (int) $days->sum('spend'))->filter()->sortDesc();
        if ($spent->isEmpty()) {
            return [['description' => "Meta (Facebook) ads — {$period}", 'qty' => 1, 'rate' => $total]];
        }
        $all = $spent->sum();
        $lines = $spent->map(fn ($s, $name) => ['description' => "{$name} — {$period}", 'qty' => 1, 'rate' => (int) floor($total * $s / $all)])->values()->all();
        $lines[0]['rate'] += $total - array_sum(array_column($lines, 'rate')); // the poisha left by rounding, so the total is exact

        return $lines;
    }

    /** @param  array<string, int>  $amounts  currency => cents */
    private function draftBill(PersonalClient $c, CarbonImmutable $from, CarbonImmutable $to, string $basis, array $amounts, ?CarbonImmutable $spentFrom = null): PersonalAdBill
    {
        $taka = self::inTaka($c, $amounts);
        if ($taka['fx'] <= 0) {
            throw new \RuntimeException('The dollar rate from KlokOne is not known yet — open KlokOne once, or set it in Client ads.');
        }
        $period = self::period($from, $to);
        $rate = rtrim(rtrim(number_format($taka['fx'], 2), '0'), '.');
        $fee = $c->ad_fee_percent > 0 ? (int) round($taka['bdt'] * $c->ad_fee_percent / 100) : 0;
        // The client sees the campaigns and the total — never my rate, the dollars or my commission.
        $items = self::campaignLines($c, $spentFrom ?? $from, $to, $taka['bdt'] + $fee, self::period($spentFrom ?? $from, $to));
        $invoice = app(PersonalInvoicing::class)->create($c, $items, CarbonImmutable::today(), CarbonImmutable::today()->addDays(7), null, null, 'BDT');
        // How it was worked out stays with me (the Money tab), not on the invoice.
        $bill = PersonalAdBill::create(['personal_client_id' => $c->id, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
            'currency' => 'BDT', 'spend' => (int) array_sum($amounts), 'basis' => $basis, 'usd' => $taka['usd'], 'fx_rate' => $taka['fx'], 'personal_invoice_id' => $invoice->id]);
        $this->applyCredit($c); // money they paid ahead goes on it at once
        $this->notify->send('invoice', "🧾 {$c->name}: ads bill ready — ".Notify::money($invoice->total, 'BDT'),
            "{$period} · ".Notify::money($taka['usd'], 'USD')." × {$rate} + commission. Drafted — check it, then send it.", route('personal.invoices.show', $invoice));

        return $bill;
    }

    /** "October 2026" for a whole month, else "3 Oct – 25 Oct 2026". */
    public static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        return $from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth()->startOfDay())
            ? $from->format('F Y')
            : $from->format($from->year === $to->year ? 'j M' : 'j M Y').' – '.$to->format('j M Y');
    }

    /**
     * The ledger: every bill (its days, amount, paid, due) and the spend not billed yet.
     *
     * @return array{bills: array<int, array<string, mixed>>, totals: array<string, array{billed: int, paid: int, due: int}>, unbilled: array<string, int>, unbilled_from: ?string}
     */
    public static function ledger(PersonalClient $c): array
    {
        $bills = PersonalAdBill::with('invoice')->where('personal_client_id', $c->id)->orderBy('period_from')->get();
        $rows = $bills->map(fn (PersonalAdBill $b) => [
            'id' => $b->id,
            'period' => self::period(CarbonImmutable::parse($b->period_from), CarbonImmutable::parse($b->period_to)),
            'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(),
            'currency' => $b->currency, 'spend' => $b->spend,
            // For me only (never on the invoice): how the total was worked out.
            'how' => $b->usd ? Notify::money((int) $b->usd, 'USD').' × '.rtrim(rtrim(number_format((float) $b->fx_rate, 2), '0'), '.').' + '.rtrim(rtrim(number_format($c->ad_fee_percent, 2), '0'), '.').'%' : null,
            'invoice' => $b->invoice ? ['id' => $b->invoice->id, 'number' => $b->invoice->number, 'total' => $b->invoice->total, 'paid' => $b->invoice->paid,
                'status' => $b->invoice->status, 'sent' => (bool) $b->invoice->sent_at, 'url' => $b->invoice->publicUrl()] : null,
        ]);
        $totals = $rows->groupBy('currency')->map(fn ($list) => [
            'billed' => (int) $list->sum(fn ($r) => $r['invoice']['total'] ?? 0),
            'paid' => (int) $list->sum(fn ($r) => $r['invoice']['paid'] ?? 0),
            'due' => (int) $list->sum(fn ($r) => $r['invoice'] && $r['invoice']['status'] !== 'void' ? max(0, $r['invoice']['total'] - $r['invoice']['paid']) : 0),
        ])->all();
        if ($c->ad_bill_basis === 'charges') {
            $open = PersonalAdCharge::where('personal_client_id', $c->id)->whereNull('personal_ad_bill_id')->get();
            $from = $open->min('date') ? CarbonImmutable::parse($open->min('date')) : null;
            $unbilled = $open->groupBy('currency')->map(fn ($list) => (int) $list->sum('amount'))->all();
        } else {
            $from = self::unbilledFrom($c);
            $unbilled = $from ? collect(self::money($c, $from, CarbonImmutable::yesterday()))->map(fn ($m) => $m['my_card'])->filter()->all() : [];
        }
        $now = $unbilled ? self::inTaka($c, $unbilled) : null;

        // For payments and charges: each bill's invoice number, days and paid state.
        $billOf = $bills->filter->invoice->mapWithKeys(fn (PersonalAdBill $b) => [$b->invoice->id => ['number' => $b->invoice->number,
            'period' => self::period(CarbonImmutable::parse($b->period_from), CarbonImmutable::parse($b->period_to))]])->all();
        $stateOf = $bills->mapWithKeys(fn (PersonalAdBill $b) => [$b->id => $b->invoice?->status ?? 'unpaid'])->all();
        $numberOf = $bills->mapWithKeys(fn (PersonalAdBill $b) => [$b->id => $b->invoice?->number])->all();
        $state = AdAccountState::whereIn('account_id', $c->adAssignments()->where('paid_by', 'me')->pluck('account_id'))->get();

        return ['bills' => $rows->reverse()->values()->all(), 'totals' => $totals, 'unbilled' => $unbilled, 'unbilled_from' => $from?->toDateString(),
            'credit' => self::credit($c),
            'payments' => PersonalAdPayment::with('account')->where('personal_client_id', $c->id)->orderByDesc('date')->orderByDesc('id')->limit(30)->get()
                ->map(fn ($p) => ['id' => $p->id, 'date' => $p->date->toDateString(), 'amount' => $p->amount, 'applied' => $p->applied, 'account' => $p->account?->name, 'note' => $p->note,
                    'paid' => collect($p->allocations ?? [])->map(fn ($a) => ['amount' => $a['amount']] + ($billOf[$a['invoice_id']] ?? ['number' => '?', 'period' => '']))->all()])->all(),
            // Meta: spent but not charged yet, and the card it will charge.
            'meta' => $state->map(fn ($s) => ['account' => $s->account_id, 'currency' => $s->currency, 'unbilled' => $s->unbilled, 'card' => $s->card, 'checked_at' => $s->checked_at->toIso8601String(),
                // when Meta charges it, roughly this on their bill
                'bill' => $s->unbilled > 0 ? (int) round(self::inTaka($c, [$s->currency => $s->unbilled])['bdt'] * (1 + $c->ad_fee_percent / 100)) : 0])->values()->all(),
            'unbilled_taka' => $now ? ['usd' => $now['usd'], 'bdt' => $now['bdt'], 'with_fee' => (int) round($now['bdt'] * (1 + $c->ad_fee_percent / 100)), 'fx' => $now['fx']] : null,
            'charges' => PersonalAdCharge::where('personal_client_id', $c->id)->orderByDesc('date')->orderByDesc('id')->limit(30)->get()
                ->map(fn ($ch) => ['id' => $ch->id, 'date' => $ch->date->toDateString(), 'currency' => $ch->currency, 'amount' => $ch->amount, 'note' => $ch->note,
                    'auto' => in_array($ch->source, ['auto', 'meta'], true), 'billed' => (bool) $ch->personal_ad_bill_id,
                    // not billed → on a bill, unpaid → part paid → paid (by the client's payments)
                    'state' => ! $ch->personal_ad_bill_id ? 'to_bill' : ($stateOf[$ch->personal_ad_bill_id] ?? 'unpaid'),
                    'invoice' => $ch->personal_ad_bill_id ? ($numberOf[$ch->personal_ad_bill_id] ?? null) : null])->all()];
    }
}
