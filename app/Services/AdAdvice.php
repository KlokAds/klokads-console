<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\PersonalAdAssignment;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Everything worth knowing about the ads, told clearly and once: every recommendation and delivery
 * issue Meta gives (whatever its kind — budget limited, learning limited, creatives, audience,
 * rejected…), plus what the numbers show — creative fatigue, a good campaign held back by its
 * budget, a cost jump, a campaign that stopped. For Tasfia (business) and every client whose ads I run.
 */
class AdAdvice
{
    /** Plain words for Meta's codes; any other kind is told in Meta's own words. */
    private const TITLES = [
        'BUDGET_LIMITED' => '💰 Budget limited',
        'CREATIVE_FATIGUE' => '🎨 Creative fatigue',
        'CREATIVE_LIMITED' => '🎨 Too few creatives',
        'LEARNING_LIMITED' => '📚 Learning limited',
        'AUCTION_OVERLAP' => '⚔️ Ads competing with each other',
        'MESSAGING_EVENTS' => '💬 Use messaging events',
        'FRAGMENTATION' => '🧩 Too many small ad sets',
        'LOW_BUDGET' => '💰 Budget too low',
        'DISAPPROVED' => '⛔ Ad rejected',
        'MIXED_FORMATS' => '🎞 Use more ad formats',
        'REELS_PC_RECOMMENDATION' => '🎬 Add Reels placements',
        'GEN_AI_MVP' => "✨ Try Meta's AI creative tools",
        'SCALE_GOOD_CAMPAIGN' => '🚀 Scale a good campaign',
        'AUTOFLOW_OPT_IN' => '✨ Turn on Advantage+ creative',
        'ADVANTAGE_PLUS_AUDIENCE' => '🎯 Try Advantage+ audience',
        'ADVANTAGE_PLUS_PLACEMENTS' => '📍 Use Advantage+ placements',
        'CONSOLIDATE_AD_SETS' => '🧩 Combine small ad sets',
        'PIXEL_SETUP' => '📡 Set up the pixel',
        'CAPI_SETUP' => '📡 Set up Conversions API',
    ];

    public function __construct(private MetaAds $meta, private Notify $notify) {}

    /**
     * The accounts to watch and whose each campaign is: [account => [campaign or '*' => [owner name, page]]].
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function watched(): array
    {
        $out = [];
        foreach (Client::whereNotNull('meta_ad_account_id')->get() as $c) {
            $out[$c->meta_ad_account_id]['*'] = [$c->company ?: $c->name, route('dashboard')];
        }
        foreach (PersonalAdAssignment::with('client')->get() as $a) {
            $out[$a->account_id][$a->campaign_id ?? '*'] = [$a->client->name, route('ad-clients.show', ['client' => $a->personal_client_id, 'tab' => 'campaigns'])];
        }

        return $out;
    }

    /** Heartbeat: Meta's advice every 6 hours; the numbers' checks once a day after 9 am. */
    public function run(?CarbonImmutable $now = null): int
    {
        if (! MetaAds::configured()) {
            return 0;
        }
        $told = 0;
        if (Cache::add('ad-advice-meta', 1, now()->addHours(6))) {
            $told += $this->fromMeta();
        }
        $local = ($now ?? CarbonImmutable::now())->setTimezone(PersonalReminders::timezone());
        if ($local->hour >= 9) {
            $told += $this->fromNumbers($local);
        }

        return $told;
    }

    /** Meta's recommendations and delivery issues, each told once a fortnight while it lasts; kept to show on the pages. */
    public function fromMeta(): int
    {
        $told = 0;
        $kept = [];
        foreach (self::watched() as $account => $owners) {
            try {
                $items = $this->meta->advice($account);
            } catch (Throwable) {
                continue;
            }
            $names = $this->campaignNames($account);
            foreach ($items as $item) {
                // Whose is it: the campaigns it names (or the account) — only campaigns that are someone's.
                $objects = collect($item['objects'] ?? [])->map(fn ($o) => (string) $o);
                $owner = $objects->map(fn ($o) => $owners[$o] ?? null)->filter()->first() ?? $owners['*'] ?? null;
                if (! $owner) {
                    continue;
                }
                $campaigns = $objects->map(fn ($o) => $names[$o] ?? null)->filter()->unique()->values();
                $type = strtoupper((string) $item['type']);
                // A known kind in plain words; otherwise Meta's own sentence, never its internal code.
                $title = self::TITLES[$type] ?? ($item['kind'] === 'issue' ? '⚠️ '.($item['title'] ?: 'Delivery issue') : '💡 '.($item['title'] ?: 'Meta suggests a change'));
                $body = implode("\n", array_filter([
                    $item['kind'] === 'issue' ? ($item['body'] ?? null) : (($item['title'] ?? null) ? $item['title'].'.' : null),
                    $item['kind'] === 'issue' ? null : ($item['body'] ?? null),
                    $campaigns->isNotEmpty() ? 'Campaign: '.$campaigns->implode(', ') : (($item['where'] ?? null) ? 'In: '.$item['where'] : null),
                ]));
                $kept[$account][] = ['owner' => $owner[0], 'title' => $title, 'body' => $body, 'url' => $item['url'] ?? null, 'kind' => $item['kind']];
                $key = 'advice:'.md5($account.'|'.$type.'|'.$objects->sort()->implode(',').'|'.($item['title'] ?? '')).':'.intdiv(CarbonImmutable::today()->dayOfYear, 14);
                $told += (int) $this->notify->once($key, 'billing', "{$owner[0]}: {$title}", $body, $owner[1]);
            }
        }
        Setting::put('ad_advice', json_encode($kept));

        return $told;
    }

    /** What the last days' numbers say, per campaign: fatigue, budget held back, a cost jump, a stop. Once each. */
    public function fromNumbers(CarbonImmutable $local): int
    {
        $told = 0;
        $day = $local->startOfDay()->subDay();
        $from = $day->subDays(10)->toDateString();

        foreach (Client::whereNotNull('meta_ad_account_id')->get() as $c) {
            $rows = MetaSpendDay::where('client_id', $c->id)->whereBetween('date', [$from, $day->toDateString()])->get();
            $told += $this->check($rows, $c->meta_campaigns ?? [], $c->company ?: $c->name, route('dashboard'), $day, false);
        }
        foreach (PersonalClient::whereHas('adAssignments')->get() as $c) {
            $rows = PersonalAdDay::where('personal_client_id', $c->id)->whereBetween('date', [$from, $day->toDateString()])->get();
            $told += $this->check($rows, $c->ad_campaigns ?? [], $c->name, route('ad-clients.show', ['client' => $c, 'tab' => 'campaigns']), $day, true);
        }

        return $told;
    }

    /** @param  Collection<int, object>  $rows  days of campaign_id, date, spend, results, impressions, clicks */
    private function check(Collection $rows, array $known, string $owner, string $url, CarbonImmutable $day, bool $alsoStopsAndJumps): int
    {
        $told = 0;
        $week = $day->format('o-W');
        $avgCost = ($r = $rows->sum('results')) > 0 ? $rows->sum('spend') / $r : null;

        foreach ($rows->groupBy('campaign_id') as $id => $days) {
            $name = $known[$id]['name'] ?? $days->last()->campaign_name;
            $cur = $days->first()->currency;
            $last3 = $days->filter(fn ($d) => $d->date->gte($day->subDays(2)));
            $before = $days->filter(fn ($d) => $d->date->lt($day->subDays(2)));
            $ctr = fn ($set) => ($i = $set->sum('impressions')) > 0 ? $set->sum('clicks') / $i : null;

            // Creative fatigue: the same people keep seeing it and click far less than before.
            $was = $ctr($before);
            $now = $ctr($last3);
            if ($last3->sum('impressions') >= 1500 && $before->sum('impressions') >= 3000 && $was && $now !== null && $now < $was * 0.6) {
                $told += (int) $this->notify->once("fatigue:{$id}:{$week}", 'billing', "{$owner}: 🎨 Creative fatigue — “{$name}”",
                    'Clicks fell to '.round($now * 100, 2).'% of views (it was '.round($was * 100, 2).'%). People have seen these ads enough — add fresh images or videos, or a new message.', $url);
            }

            // Budget limited: it spends its whole budget day after day, and its results cost no more than the rest.
            $budget = $known[$id]['daily_budget'] ?? null;
            $full = $budget ? $last3->filter(fn ($d) => $d->spend >= $budget * 0.9)->count() : 0;
            $cost = ($res = $days->sum('results')) > 0 ? $days->sum('spend') / $res : null;
            if ($full >= 3 && $cost && $avgCost && $cost <= $avgCost) {
                $told += (int) $this->notify->once("budget-limited:{$id}:{$week}", 'billing', "{$owner}: 💰 Budget limited — “{$name}”",
                    'It uses its whole '.Notify::money($budget, $cur).'/day every day and its results are among the cheapest ('.Notify::money((int) round($cost), $cur).' each). Raising the budget ~20% should bring more.', $url);
            }

            if (! $alsoStopsAndJumps) {
                continue; // the business side has these from its own daily check
            }
            $y = $days->filter(fn ($d) => $d->date->isSameDay($day));
            $prev = $days->filter(fn ($d) => $d->date->lt($day));
            // Cost jump.
            if (($yr = $y->sum('results')) > 0 && ($pr = $prev->sum('results')) >= 3 && $y->sum('spend') >= 500) {
                $base = $prev->sum('spend') / $pr;
                $c = $y->sum('spend') / $yr;
                if ($c > $base * AdPerformance::JUMP) {
                    $told += (int) $this->notify->once("cpr-jump:p:{$id}:".$day->toDateString(), 'billing', "{$owner}: 📈 “{$name}” got costlier",
                        $day->format('j M').': '.Notify::money((int) round($c), $cur).' each — it was '.Notify::money((int) round($base), $cur).' ('.round(($c / $base - 1) * 100).'% more).', $url);
                }
            }
            // Stopped delivering while still on.
            $ranBefore = $prev->filter(fn ($d) => $d->date->gte($day->subDays(3)) && $d->spend > 0)->count();
            if ($y->sum('spend') == 0 && $ranBefore >= 3 && ($known[$id]['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $told += (int) $this->notify->once("stopped:p:{$id}:".$day->toDateString(), 'billing', "{$owner}: 🛑 “{$name}” stopped spending",
                    'Nothing spent on '.$day->format('j M').' though it ran the days before and is still on. Check the ads, the budget and the payment method.', $url);
            }
        }

        return $told;
    }

    /** @return array<string, string> campaign id => name */
    private function campaignNames(string $account): array
    {
        try {
            return collect($this->meta->campaigns($account))->map(fn ($c) => $c['name'])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** What Meta said last, for one owner, to show on their page. */
    public static function forOwner(string $owner): array
    {
        return collect(json_decode((string) Setting::get('ad_advice'), true) ?: [])->flatten(1)->where('owner', $owner)->values()->take(8)->all();
    }
}
