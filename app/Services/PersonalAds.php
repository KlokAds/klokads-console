<?php

namespace App\Services;

use App\Models\PersonalAdAssignment;
use App\Models\PersonalAdBill;
use App\Models\PersonalAdCharge;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
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
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $rows;
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
            $bill = $this->draftBill($c, CarbonImmutable::parse($charges->first()->date), CarbonImmutable::parse($charges->last()->date), 'charges',
                $charges->groupBy('currency')->map(fn ($list) => (int) $list->sum('amount'))->all());
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

    /** @param  array<string, int>  $amounts  currency => cents */
    private function draftBill(PersonalClient $c, CarbonImmutable $from, CarbonImmutable $to, string $basis, array $amounts): PersonalAdBill
    {
        $taka = self::inTaka($c, $amounts);
        if ($taka['fx'] <= 0) {
            throw new \RuntimeException('The dollar rate from KlokOne is not known yet — open KlokOne once, or set it in Client ads.');
        }
        $period = self::period($from, $to);
        $what = $basis === 'charges' ? 'Meta charges' : 'Meta ads';
        $rate = rtrim(rtrim(number_format($taka['fx'], 2), '0'), '.');
        $items = [['description' => "{$what} — {$period}: ".implode(' + ', $taka['lines'])." × {$rate}", 'qty' => 1, 'rate' => $taka['bdt']]];
        if ($c->ad_fee_percent > 0) {
            $items[] = ['description' => 'Commission ('.rtrim(rtrim(number_format($c->ad_fee_percent, 2), '0'), '.').'%)', 'qty' => 1, 'rate' => (int) round($taka['bdt'] * $c->ad_fee_percent / 100)];
        }
        $invoice = app(PersonalInvoicing::class)->create($c, $items, CarbonImmutable::today(), CarbonImmutable::today()->addDays(7),
            "{$what} my card paid, {$period}. Dollar rate {$rate}.", null, 'BDT');
        $bill = PersonalAdBill::create(['personal_client_id' => $c->id, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
            'currency' => 'BDT', 'spend' => (int) array_sum($amounts), 'basis' => $basis, 'usd' => $taka['usd'], 'fx_rate' => $taka['fx'], 'personal_invoice_id' => $invoice->id]);
        $this->notify->send('invoice', "🧾 {$c->name}: ads bill ready — ".Notify::money($invoice->total, 'BDT'),
            "{$period} · ".Notify::money($taka['usd'], 'USD')." × {$rate} + commission. Drafted in My invoices — check it, then send it.", route('personal.invoices.index'));

        return $bill;
    }

    /** "October 2026" for a whole month, else "3 Oct – 25 Oct 2026". */
    public static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
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

        return ['bills' => $rows->reverse()->values()->all(), 'totals' => $totals, 'unbilled' => $unbilled, 'unbilled_from' => $from?->toDateString(),
            'unbilled_taka' => $now ? ['usd' => $now['usd'], 'bdt' => $now['bdt'], 'with_fee' => (int) round($now['bdt'] * (1 + $c->ad_fee_percent / 100)), 'fx' => $now['fx']] : null,
            'charges' => PersonalAdCharge::where('personal_client_id', $c->id)->orderByDesc('date')->orderByDesc('id')->limit(30)->get()
                ->map(fn ($ch) => ['id' => $ch->id, 'date' => $ch->date->toDateString(), 'currency' => $ch->currency, 'amount' => $ch->amount, 'note' => $ch->note, 'billed' => (bool) $ch->personal_ad_bill_id])->all()];
    }
}
