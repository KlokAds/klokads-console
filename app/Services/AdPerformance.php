<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MetaSpendDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How the campaigns are doing, not just what they cost: results and cost per result, budgets,
 * suggestions, and alerts — a campaign stopped or rejected, a cost per result that jumped —
 * plus a summary every Monday.
 */
class AdPerformance
{
    /** A day's cost per result this much above the week before is a jump. */
    public const JUMP = 1.5;

    /** Delivery states worth a word when a campaign enters them. */
    public const STATUS_TEXT = [
        'ACTIVE' => '▶️ running again',
        'PAUSED' => '⏸ paused',
        'CAMPAIGN_PAUSED' => '⏸ paused',
        'ADSET_PAUSED' => '⏸ ad set paused',
        'DISAPPROVED' => '⛔ rejected by Meta',
        'WITH_ISSUES' => '⚠️ has delivery issues',
        'PENDING_REVIEW' => '🕒 in review',
        'IN_PROCESS' => '🕒 being processed',
        'ARCHIVED' => '🗄 archived',
        'DELETED' => '🗑 deleted',
    ];

    public function __construct(private MetaAds $meta, private Notify $notify) {}

    /** "12 leads", "1 message", "340 link clicks" — what the campaign's results are. */
    public static function noun(?string $type, int $n = 2): string
    {
        $t = strtolower((string) $type);
        $word = match (true) {
            str_contains($t, 'messaging') || str_contains($t, 'conversation') => 'message',
            str_contains($t, 'purchase') => 'purchase',
            str_contains($t, 'lead') || str_contains($t, 'complete_registration') => 'lead',
            str_contains($t, 'app_install') => 'install',
            str_contains($t, 'video') || str_contains($t, 'thruplay') => 'video view',
            str_contains($t, 'landing_page_view') => 'landing page view',
            str_contains($t, 'link_click') || $t === 'clicks' || $t === 'outbound_click' => 'link click',
            $t === 'reach' => 'person reached',
            str_contains($t, 'follow') || $t === 'like' => 'follower',
            str_contains($t, 'engagement') || str_contains($t, 'post_') || str_contains($t, 'reaction') => 'engagement',
            default => 'result',
        };

        return $n === 1 ? $word : match ($word) {
            'person reached' => 'people reached',
            default => $word.'s',
        };
    }

    /** The campaign's goal in words, from Meta's objective (OUTCOME_* and the older names). */
    public static function goal(?string $objective, ?string $resultType = null): string
    {
        $o = strtoupper((string) $objective);
        $t = strtolower((string) $resultType);

        return match (true) {
            in_array($o, ['OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS', 'VIDEO_VIEWS'], true) => 'Awareness',
            in_array($o, ['OUTCOME_TRAFFIC', 'LINK_CLICKS'], true) => 'Traffic',
            in_array($o, ['OUTCOME_LEADS', 'LEAD_GENERATION'], true) => 'Leads',
            in_array($o, ['OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES'], true) => 'Sales',
            in_array($o, ['OUTCOME_APP_PROMOTION', 'APP_INSTALLS'], true) => 'App',
            $o === 'MESSAGES' || str_contains($t, 'messaging') || str_contains($t, 'conversation') => 'Messages',
            in_array($o, ['OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'EVENT_RESPONSES'], true) => 'Engagement',
            str_contains($t, 'video') || str_contains($t, 'thruplay') || $t === 'reach' => 'Awareness',
            str_contains($t, 'link_click') || str_contains($t, 'landing_page') => 'Traffic',
            str_contains($t, 'lead') => 'Leads',
            str_contains($t, 'purchase') => 'Sales',
            default => 'Other',
        };
    }

    /**
     * What a campaign brought, the way its goal counts it: awareness by people reached (and the
     * cost per 1,000), everything else by its own result (leads, messages, link clicks…) and the cost each.
     *
     * @return array{goal: string, results: int, result_label: string, cost_per_result: ?int, per: string}
     */
    public static function present(?string $objective, ?string $resultType, int $spend, int $results, int $reach): array
    {
        $goal = self::goal($objective, $resultType);
        if ($goal === 'Awareness' && $reach > 0) {
            return ['goal' => $goal, 'results' => $reach, 'result_label' => self::noun('reach', $reach), 'cost_per_result' => (int) round($spend * 1000 / $reach), 'per' => 'per 1,000 reached'];
        }
        $label = self::noun($resultType, $results);

        return ['goal' => $goal, 'results' => $results, 'result_label' => $label, 'cost_per_result' => $results > 0 ? (int) round($spend / $results) : null, 'per' => 'per '.self::noun($resultType, 1)];
    }

    /**
     * Per campaign over a period: spend, results, cost per result, clicks and CTR, budget and status.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function table(Client $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $known = $client->meta_campaigns ?? [];

        return MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->groupBy('campaign_id')->map(function (Collection $days, $id) use ($known) {
                $spend = (int) $days->sum('spend');
                $results = (int) $days->sum('results');
                $impressions = (int) $days->sum('impressions');
                $clicks = (int) $days->sum('clicks');
                $type = $days->whereNotNull('result_type')->last()?->result_type;

                return [
                    'id' => (string) $id,
                    'name' => $known[$id]['name'] ?? $days->last()->campaign_name,
                    'status' => $known[$id]['status'] ?? null,
                    'daily_budget' => $known[$id]['daily_budget'] ?? null,
                    'spend' => $spend,
                    ...self::present($known[$id]['objective'] ?? null, $type, $spend, $results, (int) $days->sum('reach')),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                ];
            })->filter(fn ($c) => $c['spend'] > 0 || $c['results'] > 0)->sortByDesc('spend')->values()->all();
    }

    /**
     * What to do next, from the last 7 finished days: where the money works and where it doesn't.
     * Advice only — nothing is changed in Meta.
     *
     * @return array<int, array{tone: string, text: string}>
     */
    public static function suggestions(Client $client, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rows = collect(self::table($client, $today->subDays(7), $today->subDay()));
        $cur = strtoupper($client->currency ?: 'SGD');
        $m = fn ($c) => Notify::money((int) $c, $cur);
        $out = [];

        // Costs compare only within the same goal: a lead and a person reached are not the same thing.
        $withResults = $rows->filter(fn ($c) => $c['results'] >= 3 && $c['cost_per_result'] && $c['goal'] !== 'Awareness');
        $best = $withResults->sortBy('cost_per_result')->first();

        foreach ($rows as $c) {
            $same = $withResults->where('goal', $c['goal']);
            $bestOfGoal = $same->sortBy('cost_per_result')->first();
            $avgCost = $same->sum('results') > 0 ? $same->sum('spend') / $same->sum('results') : null;
            if ($c['results'] === 0 && $c['spend'] >= 1000) {
                $out[] = ['tone' => 'bad', 'text' => "“{$c['name']}” spent {$m($c['spend'])} in 7 days with no {$c['result_label']}. Check the ad, the audience or the form — or pause it."];
            } elseif ($avgCost && $bestOfGoal && $c['id'] !== $bestOfGoal['id'] && $c['results'] >= 3 && $c['cost_per_result'] > $avgCost * self::JUMP) {
                $out[] = ['tone' => 'warn', 'text' => "“{$c['name']}” costs {$m($c['cost_per_result'])} {$c['per']} — {$m($bestOfGoal['cost_per_result'])} on “{$bestOfGoal['name']}”. Move some budget from it to “{$bestOfGoal['name']}”."];
            }
        }
        // The cheapest campaign that keeps hitting its daily budget: it could take more.
        if ($best && $best['daily_budget'] && ($best['spend'] / 7) >= $best['daily_budget'] * 0.9) {
            $out[] = ['tone' => 'good', 'text' => "“{$best['name']}” gets the cheapest results ({$m($best['cost_per_result'])} each) and uses its whole {$m($best['daily_budget'])}/day budget. Raising it by about 20% (to {$m($best['daily_budget'] * 1.2)}) should bring more."];
        } elseif ($best) {
            $out[] = ['tone' => 'good', 'text' => "Best this week: “{$best['name']}” — {$best['results']} {$best['result_label']} at {$m($best['cost_per_result'])} each."];
        }
        if ($rows->isEmpty()) {
            $out[] = ['tone' => 'neutral', 'text' => 'No ads ran in the last 7 days.'];
        }

        return $out;
    }

    /** Heartbeat: statuses (hourly), yesterday's checks and Monday's summary (once each). */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        if (! MetaAds::configured()) {
            return 0;
        }
        $done = 0;
        foreach (Client::whereNotNull('meta_ad_account_id')->where('billing_mode', 'prepaid')->get() as $client) {
            try {
                if (Cache::add('campaign-status:'.$client->id, 1, now()->addMinutes(55))) {
                    $this->watchCampaigns($client);
                }
                $this->checkYesterday($client->fresh(), $now);
                $this->weekly($client->fresh(), $now);
                $done++;
            } catch (Throwable) {
                // a Meta hiccup must not stop the other clients; the next run tries again
            }
        }

        return $done;
    }

    /** A campaign changed state (stopped, rejected, running again…): say so. The first look only remembers. */
    public function watchCampaigns(Client $client): void
    {
        $now = $this->meta->campaigns($client->meta_ad_account_id);
        $before = $client->meta_campaigns;
        $client->update(['meta_campaigns' => $now]);
        if ($before === null) {
            return;
        }
        $name = $client->company ?: $client->name;
        foreach ($now as $id => $c) {
            $was = $before[$id]['status'] ?? null;
            if ($was === null || $was === $c['status'] || ! isset(self::STATUS_TEXT[$c['status']])) {
                continue;
            }
            $bad = in_array($c['status'], ['DISAPPROVED', 'WITH_ISSUES'], true);
            $this->notify->send('billing', "{$name}: “{$c['name']}” ".self::STATUS_TEXT[$c['status']],
                ($bad ? 'Ads in it are not running. Open Ads Manager to see why and fix it. ' : '').'It was '.strtolower(str_replace('_', ' ', $was)).'.',
                route('ad-money.index', ['client' => $client->id, 'period' => 'last7']), 'klok-campaign-'.$id);
        }
    }

    /** After 9 am: did a campaign's cost per result jump yesterday, or did a running campaign stop spending? */
    public function checkYesterday(Client $client, CarbonImmutable $now): void
    {
        $local = $now->setTimezone(PersonalReminders::timezone());
        if ($local->hour < 9) {
            return;
        }
        $yesterday = $local->startOfDay()->subDay();
        $cur = strtoupper($client->currency ?: 'SGD');
        $name = $client->company ?: $client->name;
        $days = MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$yesterday->subDays(7)->toDateString(), $yesterday->toDateString()])->get()->groupBy('campaign_id');

        foreach ($days as $id => $rows) {
            $y = $rows->filter(fn ($r) => $r->date->isSameDay($yesterday));
            $before = $rows->reject(fn ($r) => $r->date->isSameDay($yesterday));
            $campaign = $rows->last()->campaign_name;
            $spend = (int) $y->sum('spend');
            $results = (int) $y->sum('results');
            $type = $rows->whereNotNull('result_type')->last()?->result_type;

            // Cost per result jumped (with enough of both to mean something).
            $baseResults = (int) $before->sum('results');
            if ($results > 0 && $baseResults >= 3 && $spend >= 500) {
                $base = $before->sum('spend') / $baseResults;
                $cost = $spend / $results;
                if ($cost > $base * self::JUMP) {
                    $this->notify->once("cpr-jump:{$client->id}:{$id}:".$yesterday->toDateString(), 'billing', "📈 {$name}: “{$campaign}” got costlier",
                        $yesterday->format('j M').': '.Notify::money((int) round($cost), $cur).' per '.self::noun($type, 1).' — the week before it was '.Notify::money((int) round($base), $cur)
                        .' ('.round(($cost / $base - 1) * 100).'% more). '.$results.' '.self::noun($type, $results).' for '.Notify::money($spend, $cur).'.',
                        route('ad-money.index', ['client' => $client->id, 'period' => 'last7']));
                }
            }

            // It was spending every day, and yesterday nothing, while Meta still says it runs.
            $status = ($client->meta_campaigns[$id]['status'] ?? 'ACTIVE');
            $recent = $before->filter(fn ($r) => $r->date->gte($yesterday->subDays(3)) && $r->spend > 0)->pluck('date')->unique()->count();
            if ($spend === 0 && $recent >= 3 && $status === 'ACTIVE') {
                $this->notify->once("stopped:{$client->id}:{$id}:".$yesterday->toDateString(), 'billing', "🛑 {$name}: “{$campaign}” stopped spending",
                    'Nothing spent on '.$yesterday->format('j M').' though it ran the days before and is still on. Check the ads, the budget and the payment method in Ads Manager.',
                    route('ad-money.index', ['client' => $client->id, 'period' => 'last7']));
            }
        }
    }

    /** "leads" when all the results are leads; "results" when they are of different kinds. */
    private function resultWord(Client $client, CarbonImmutable $start, CarbonImmutable $end, int $n): string
    {
        $types = MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('results', '>', 0)->pluck('result_type')->map(fn ($t) => self::noun($t, 1))->unique();

        return $types->count() === 1 ? self::noun(MetaSpendDay::where('client_id', $client->id)->where('results', '>', 0)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->value('result_type'), $n) : ($n === 1 ? 'result' : 'results');
    }

    /** Monday after 9 am: last week against the week before, the best campaign, the balance, and what to do. */
    public function weekly(Client $client, CarbonImmutable $now): void
    {
        $local = $now->setTimezone(PersonalReminders::timezone());
        if (! $local->isMonday() || $local->hour < 9) {
            return;
        }
        $end = $local->startOfDay()->subDay();
        $start = $end->subDays(6);
        $rows = collect(self::table($client, $start, $end));
        $prev = collect(self::table($client, $start->subDays(7), $start->subDay()));
        if ($rows->isEmpty() && $prev->isEmpty()) {
            return;
        }
        $cur = strtoupper($client->currency ?: 'SGD');
        $m = fn ($c) => Notify::money((int) $c, $cur);
        $spend = (int) $rows->sum('spend');
        $results = (int) $rows->sum('results');
        $prevSpend = (int) $prev->sum('spend');
        $prevResults = (int) $prev->sum('results');
        $change = fn ($a, $b) => $b > 0 ? ' ('.($a >= $b ? '+' : '').round(($a / $b - 1) * 100).'%)' : '';
        $status = app(PrepaidBalance::class)->status($client);
        $tips = collect(self::suggestions($client, $local->startOfDay()))->pluck('text')->take(2);

        $this->notify->once("weekly:{$client->id}:".$start->toDateString(), 'billing', '🗓 '.($client->company ?: $client->name).' · week of '.$start->format('j M'),
            implode("\n", array_filter([
                'Spent '.$m($spend).$change($spend, $prevSpend).' · '.$results.' '.$this->resultWord($client, $start, $end, $results).$change($results, $prevResults)
                    .($results > 0 ? ' · '.$m($spend / $results).' each' : ''),
                'Balance '.$m($status['balance']).($status['days_left'] !== null ? " ≈ {$status['days_left']} days" : ''),
                ...$tips->map(fn ($t) => '💡 '.$t)->all(),
            ])),
            route('ad-money.index', ['client' => $client->id, 'period' => 'last7']));
    }
}
