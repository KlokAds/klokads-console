<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Support\Business;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pay-as-you-spend billing for clients whose ads run on our Meta card:
 * read their campaigns' daily spend; when finished days add up to the threshold
 * (e.g. 500), charge their saved card for that spend + commission + card fee.
 */
class SpendBilling
{
    /** A charge bigger than this many thresholds waits for a manual go-ahead. */
    private const SAFETY_MULTIPLE = 4;

    /** History is imported this many days per step (one bridge request). */
    private const HISTORY_STEP_DAYS = 31;

    /** Meta keeps insights for 37 months; from the 1st of a month, 36 stays inside. */
    private const HISTORY_MONTHS = 36;

    public function __construct(private MetaAds $meta, private StripeService $stripe, private Notify $notify) {}

    /** Every client with a Meta ad account: refresh spend, then bill if due. At most once an hour. */
    public function runAll(bool $force = false): array
    {
        if (! MetaAds::configured() || (! $force && ! Cache::add('spend-billing-run', 1, now()->addMinutes(55)))) {
            return ['skipped' => true];
        }

        $done = [];
        foreach (Client::whereNotNull('meta_ad_account_id')->get() as $client) {
            try {
                $this->sync($client);
                $done[$client->id] = ($client->billing_mode === 'prepaid'
                    ? app(PrepaidBalance::class)->run($client->fresh())
                    : $this->chargeIfDue($client->fresh()))?->number ?? 'ok';
            } catch (Throwable $e) {
                $client->update(['spend_error' => mb_substr($e->getMessage(), 0, 500)]);
                $done[$client->id] = 'error';
            }
        }

        return $done;
    }

    /** Pull this month's (and anything unbilled since) daily campaign spend from Meta. */
    public function sync(Client $client): void
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfMonth();
        if ($client->spend_billed_until && $client->spend_billed_until->lt($from)) {
            $from = CarbonImmutable::parse($client->spend_billed_until)->addDay();
        }

        $currency = $this->meta->account($client->meta_ad_account_id)['currency'];
        $this->store($client, $this->meta->dailyCampaignSpend($client->meta_ad_account_id, $from, $today, $client->meta_campaign_filter), $currency);

        // Charge in the ad account's own currency (Tasfia's runs in SGD).
        $client->update(['spend_synced_at' => now(), 'spend_error' => null, 'currency' => $client->currency ?: strtolower($currency)]);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function store(Client $client, array $rows, string $currency): void
    {
        DB::transaction(function () use ($client, $rows, $currency) {
            foreach ($rows as $r) {
                $day = MetaSpendDay::firstOrNew(['client_id' => $client->id, 'campaign_id' => $r['campaign_id'], 'date' => $r['date']]);
                if ($day->invoice_id) {
                    continue; // already billed — keep exactly what was charged
                }
                $day->fill($r + ['currency' => $currency])->save();
            }
        });
    }

    /**
     * Bring in the account's whole history: find the first month it spent, then import back to it
     * a step at a time (see importHistory). History is a record only — it is never billed, because
     * billing counts from spend_billed_until.
     */
    public function startHistory(Client $client): ?CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $months = collect($this->meta->monthlySpend($client->meta_ad_account_id, $today->subMonths(self::HISTORY_MONTHS)->startOfMonth(), $today))
            ->filter(fn ($m) => $m['spend'] > 0)->sortBy('month');
        $first = $months->first() ? CarbonImmutable::parse($months->first()['month'].'-01') : null;

        $client->update([
            'history_from' => $first?->toDateString(),
            'history_cursor' => $first ? $today->startOfMonth()->toDateString() : null,
            // Billing starts now: nothing from the history is ever charged.
            'spend_billed_until' => $client->spend_billed_until ?? CarbonImmutable::yesterday()->toDateString(),
        ]);

        return $first;
    }

    /** Heartbeat: keep importing any history still missing (a few steps per run). */
    public function importAllHistory(): int
    {
        if (! MetaAds::configured()) {
            return 0;
        }
        $n = 0;
        foreach (Client::whereNotNull('meta_ad_account_id')->whereNotNull('history_cursor')->whereColumn('history_cursor', '>', 'history_from')->get() as $client) {
            try {
                $this->importHistory($client);
                $n++;
            } catch (Throwable $e) {
                $client->update(['spend_error' => 'History: '.mb_substr($e->getMessage(), 0, 480)]);
            }
        }

        return $n;
    }

    /** Import the next step(s) of history, newest first. Returns how far back it now goes (null when nothing to do). */
    public function importHistory(Client $client, int $steps = 2): ?CarbonImmutable
    {
        if (! $client->history_from || ! $client->history_cursor || $client->history_cursor->lte($client->history_from)) {
            return null;
        }
        // One import at a time per client (the heartbeat and a button press can meet).
        $lock = Cache::lock('history-import:'.$client->id, 600);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $this->importSteps($client->fresh(), $steps);
        } finally {
            $lock->release();
        }
    }

    private function importSteps(Client $client, int $steps): ?CarbonImmutable
    {
        if (! $client->history_cursor || $client->history_cursor->lte($client->history_from)) {
            return null;
        }
        $currency = $this->meta->account($client->meta_ad_account_id)['currency'];
        $cursor = CarbonImmutable::parse($client->history_cursor);
        $from = CarbonImmutable::parse($client->history_from);
        for ($i = 0; $i < $steps && $cursor->gt($from); $i++) {
            $start = $cursor->subDays(self::HISTORY_STEP_DAYS)->max($from);
            $this->store($client, $this->meta->dailyCampaignSpend($client->meta_ad_account_id, $start, $cursor->subDay(), $client->meta_campaign_filter), $currency);
            $cursor = $start;
            $client->update(['history_cursor' => $cursor->toDateString()]);
        }

        if ($cursor->lte($from)) {
            $total = (int) MetaSpendDay::where('client_id', $client->id)->sum('spend');
            // Told once per client: a later refill (e.g. to add results) says nothing.
            $this->notify->once('history-in:'.$client->id, 'billing', '📚 '.$this->name($client).': full ad history in',
                'Since '.$from->format('M Y').' — '.$this->money($total, $currency).' spent in all.', route('clients.show', $client));
        }

        return $cursor;
    }

    /** Finished days (before today) not billed yet. */
    public function unbilled(Client $client)
    {
        return MetaSpendDay::where('client_id', $client->id)->whereNull('invoice_id')
            ->where('date', '<', CarbonImmutable::today()->toDateString())
            ->when($client->spend_billed_until, fn ($q, $d) => $q->where('date', '>', $d->toDateString()))
            ->orderBy('date')->get();
    }

    /** Charge when unbilled spend reached the threshold. Returns the paid invoice, if any. */
    public function chargeIfDue(Client $client, bool $manual = false): ?Invoice
    {
        if ($client->billing_mode === 'prepaid' || (! $client->spend_billing && ! $manual)) {
            return null;
        }

        $days = $this->unbilled($client);
        $spend = (int) $days->sum('spend');
        if ($days->isEmpty() || (! $manual && $spend < $client->spend_threshold)) {
            return null;
        }

        // A failed card is retried once a day, not every hour.
        if (! $manual && $client->spend_error && $client->spend_attempted_at?->gt(now()->subDay())) {
            return null;
        }

        $currency = strtolower($days->first()->currency);
        if ($currency !== $this->stripe->currencyFor($client)) {
            return $this->failed($client, "The ad account is in {$days->first()->currency} but Stripe charges in ".strtoupper($this->stripe->currencyFor($client)).'. Charge this one manually.');
        }
        if (! $manual && $spend > $client->spend_threshold * self::SAFETY_MULTIPLE) {
            return $this->failed($client, 'Unbilled spend '.number_format($spend / 100, 2).' is far above the threshold — check it and use "Charge now" on the client page.');
        }

        $budget = self::spendBudget($spend, Pricing::forClient($client));
        $from = $days->first()->date;
        $to = $days->last()->date;
        $period = $from->format('j M').($from->ne($to) ? ' – '.$to->format('j M') : '');

        $client->update(['spend_attempted_at' => now()]);
        try {
            $invoice = $this->stripe->chargeNow($client, $budget['charge'], "Meta Ads spend {$period}", 'meta_ads', null, $budget);
        } catch (Throwable $e) {
            return $this->failed($client, 'Card charge failed: '.$e->getMessage());
        }

        DB::transaction(function () use ($client, $days, $invoice, $from, $to, $spend) {
            $invoice->update([
                'template' => Business::templateFor('meta_ads'),
                'details' => [
                    'ad_account_id' => preg_replace('/^act_/', '', $client->meta_ad_account_id),
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'campaigns' => $days->groupBy('campaign_id')->map(fn ($list) => [
                        'name' => $list->first()->campaign_name,
                        'detail' => null,
                        'quantity' => (int) $list->sum('impressions'),
                        'unit' => 'Impressions',
                        'amount' => (int) $list->sum('spend'),
                    ])->values()->all(),
                ],
            ]);
            MetaSpendDay::whereKey($days->pluck('id'))->update(['invoice_id' => $invoice->id]);
            $client->update(['spend_billed_until' => $to->toDateString(), 'spend_error' => null]);

            // The spend itself is what we paid Meta for this client.
            Expense::create([
                'client_id' => $client->id, 'category' => 'meta_ads', 'vendor' => 'Meta',
                'description' => 'Ad spend '.$from->format('j M').' – '.$to->format('j M Y').' (billed '.$invoice->number.')',
                'amount' => $spend, 'paid_via' => 'card', 'reference' => $invoice->number, 'spent_on' => $to->toDateString(),
            ]);
        });

        $this->tell($client, "💳 Charged {$this->name($client)}", 'Meta spend '.$this->money($spend, $currency).' + '.rtrim(rtrim(number_format($budget['commission_rate'], 2), '0'), '.').'% = '.$this->money($budget['charge'], $currency).' (incl. card fee)');

        return $invoice->fresh();
    }

    /**
     * Charge = spend + commission on it, plus the card fee so the full amount reaches us.
     * Commission is reported as commission; the spend as ad budget we paid Meta.
     *
     * @return array<string, mixed>
     */
    public static function spendBudget(int $spend, Pricing $pricing): array
    {
        $commission = (int) round($spend * $pricing->commissionRate / 100);
        $b = $pricing->breakdown($spend + $commission);

        return ['commission' => $commission, 'ad_budget' => $spend, 'spend' => $spend, 'mode' => 'spend'] + $b;
    }

    public function failed(Client $client, string $message): null
    {
        $first = $client->spend_error !== $message;
        $client->update(['spend_error' => $message, 'spend_attempted_at' => now()]);
        if ($first) {
            $this->tell($client, "⚠️ {$this->name($client)}: not charged", $message);
        }

        return null;
    }

    /** To the MH-Bot feed and the phones, like every other alert. */
    public function tell(Client $client, string $title, string $body): void
    {
        try {
            $this->notify->send('billing', $title, $body, route('clients.show', $client), 'klok-billing');
        } catch (Throwable) {
            // a notification problem must never undo a charge
        }
    }

    public function name(Client $client): string
    {
        return $client->company ?: $client->name;
    }

    public function money(int $cents, string $currency): string
    {
        return strtoupper($currency).' '.number_format($cents / 100, 2);
    }
}
