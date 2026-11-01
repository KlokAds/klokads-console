<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Every morning (after 9 am), one notification per ad account owner — Tasfia and each client
 * whose ads I run — about yesterday: spend, results and the cost of each against the week
 * before, campaign by campaign, and what to do about what stands out.
 */
class AdDailyReport
{
    /** Cost per result this much above the week's average is worth a word. */
    private const COSTLIER = 1.5;

    public function __construct(private Notify $notify) {}

    /** Heartbeat: once a day per owner; nothing before 9 am. */
    public function run(?CarbonImmutable $now = null): int
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(PersonalReminders::timezone());
        if ($local->hour < 9) {
            return 0;
        }
        $day = $local->startOfDay()->subDay();
        $sent = 0;

        foreach (Client::whereNotNull('meta_ad_account_id')->get() as $client) {
            try {
                $rows = MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$day->subDays(7)->toDateString(), $day->toDateString()])->get();
                $sent += (int) $this->report("business:{$client->id}", $client->company ?: $client->name, $rows, $client->meta_campaigns ?? [], $day,
                    strtoupper($client->currency ?: 'SGD'), route('dashboard'));
            } catch (Throwable $e) {
                report($e);
            }
        }
        foreach (PersonalClient::whereHas('adAssignments')->get() as $client) {
            try {
                $rows = PersonalAdDay::where('personal_client_id', $client->id)->whereBetween('date', [$day->subDays(7)->toDateString(), $day->toDateString()])->get();
                $sent += (int) $this->report("personal:{$client->id}", $client->name, $rows, $client->ad_campaigns ?? [], $day,
                    strtoupper($rows->first()?->currency ?: 'USD'), route('ad-clients.show', ['client' => $client->id, 'tab' => 'campaigns']));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * @param  Collection<int, MetaSpendDay|PersonalAdDay>  $rows  the day and the 7 before it
     * @param  array<string, array<string, mixed>>  $known  the campaigns as Meta lists them (status, budget, objective…)
     */
    public function report(string $owner, string $name, Collection $rows, array $known, CarbonImmutable $day, string $currency, string $url): bool
    {
        $m = fn ($cents) => Notify::money((int) round($cents), $currency);
        $isDay = fn ($r) => $r->date->isSameDay($day);
        $tips = [];

        $campaigns = $rows->groupBy('campaign_id')->map(function (Collection $list, $id) use ($known, $isDay) {
            $y = $list->filter($isDay);
            $before = $list->reject($isDay);
            $type = $list->whereNotNull('result_type')->last()?->result_type;
            $objective = $known[$id]['objective'] ?? null;
            $now = AdPerformance::present($objective, $type, (int) $y->sum('spend'), (int) $y->sum('results'), (int) $y->sum('reach'));
            $week = AdPerformance::present($objective, $type, (int) $before->sum('spend'), (int) $before->sum('results'), (int) $before->sum('reach'));

            return ['id' => (string) $id, 'name' => $known[$id]['name'] ?? $list->last()->campaign_name, 'spend' => (int) $y->sum('spend'),
                'budget' => $known[$id]['daily_budget'] ?? null, 'now' => $now, 'week' => $week, 'week_spend' => (int) $before->sum('spend')];
        })->filter(fn ($c) => $c['spend'] > 0)->sortByDesc('spend')->values();

        // On at Meta, not past its last day, started before yesterday — yet nothing spent.
        $silent = collect($known)->filter(fn ($k, $id) => ($k['status'] ?? null) === 'ACTIVE' && (! ($k['end'] ?? null) || $k['end'] >= $day->toDateString())
            && ($k['start'] ?? '0000') < $day->toDateString() && ! $campaigns->contains('id', (string) $id))
            ->map(fn ($k) => $k['name']);
        // Meta keeps old campaigns "ACTIVE": only those that spent lately count as stopped.
        $silent = $silent->filter(fn ($n, $id) => $rows->where('campaign_id', $id)->where('spend', '>', 0)->isNotEmpty() || (($known[$id]['start'] ?? '') >= $day->subDays(7)->toDateString()));

        if ($campaigns->isEmpty() && $silent->isEmpty()) {
            return false;
        }

        $lines = [];
        $spend = $campaigns->sum('spend');
        $lines[] = 'Spent '.$m($spend).' on '.$campaigns->count().' campaign'.($campaigns->count() === 1 ? '' : 's').'.';
        foreach ($campaigns->take(5) as $c) {
            $n = $c['now'];
            $lines[] = '• '.mb_strimwidth($c['name'], 0, 40, '…').': '.$m($c['spend']).' · '.number_format($n['results']).' '.$n['result_label']
                .($n['cost_per_result'] ? ' · '.$m($n['cost_per_result']).' '.$n['per'] : '');
        }

        foreach ($campaigns as $c) {
            $n = $c['now'];
            $w = $c['week'];
            if ($n['results'] === 0 && $n['goal'] !== 'Awareness' && $c['spend'] >= max(500, (int) ($w['cost_per_result'] ?? 0) * 2)) {
                $tips[] = "“{$c['name']}” spent {$m($c['spend'])} with no {$n['result_label']}. Check the ad, the audience and the form or chat link — pause it if this repeats tomorrow.";
            } elseif ($n['cost_per_result'] && $w['cost_per_result'] && $w['results'] >= 3 && $n['cost_per_result'] > $w['cost_per_result'] * self::COSTLIER) {
                $tips[] = "“{$c['name']}” got costlier: {$m($n['cost_per_result'])} {$n['per']}, the week before {$m($w['cost_per_result'])}. People may be tired of the ad — try a fresh creative, or narrow the audience.";
            }
        }
        foreach ($silent as $campaign) {
            $tips[] = "“{$campaign}” is on but spent nothing. Check in Ads Manager: its ad set and ads are on, no ad is in review or rejected, and the payment method works.";
        }
        // The cheapest that used its whole budget could take more.
        $best = $campaigns->filter(fn ($c) => $c['now']['cost_per_result'] && $c['now']['results'] >= 3 && $c['now']['goal'] !== 'Awareness')->sortBy(fn ($c) => $c['now']['cost_per_result'])->first();
        if ($best && $best['budget'] && $best['spend'] >= $best['budget'] * 0.9 && (! $best['week']['cost_per_result'] || $best['now']['cost_per_result'] <= $best['week']['cost_per_result'])) {
            $tips[] = "“{$best['name']}” brings the cheapest results and used its whole {$m($best['budget'])}/day. Raise it about 20% (to {$m($best['budget'] * 1.2)}) for more.";
        }

        $body = implode("\n", [...$lines, ...array_map(fn ($t) => '💡 '.$t, array_slice($tips, 0, 3)), ...($tips ? [] : ['✅ Nothing needs a hand today.'])]);

        return $this->notify->once("ads-daily:{$owner}:".$day->toDateString(), 'billing', "📊 {$name} · ".$day->format('D j M'), $body, $url);
    }
}
