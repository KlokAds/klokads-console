<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only Meta Marketing API calls: through Klok Pilot's bridge (where the ad accounts are
 * connected — preferred), or with a System User token from Settings.
 */
class MetaAds
{
    public const VERSION = 'v26.0';

    /** The bridge answers at most this many days per spend request. */
    private const BRIDGE_DAYS = 31;

    public static function configured(): bool
    {
        return self::viaPilot() || (bool) Setting::get('meta_token');
    }

    public static function viaPilot(): bool
    {
        return filled(Setting::get('pilot_url')) && filled(Setting::get('pilot_bridge_key'));
    }

    /** @return array<string, mixed> */
    private function pilot(string $path, array $params = []): array
    {
        $res = Http::timeout(120)->retry(2, 1000, throw: false)->acceptJson()
            ->withHeaders(['X-Console-Key' => Setting::get('pilot_bridge_key')])
            ->get(rtrim(Setting::get('pilot_url'), '/').'/internal/console/'.ltrim($path, '/'), $params);

        if ($res->failed()) {
            throw new RuntimeException('Klok Pilot: '.($res->json('error.message') ?? $res->json('message') ?? 'request failed ('.$res->status().')'));
        }

        return $res->json();
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $params = []): array
    {
        $token = Setting::get('meta_token');
        if (! $token) {
            throw new RuntimeException('Add the Meta token in Settings → Meta first.');
        }

        $res = Http::timeout(30)->retry(2, 500, throw: false)
            ->get(str_starts_with($path, 'https://') ? $path : 'https://graph.facebook.com/'.self::VERSION.'/'.ltrim($path, '/'), $params + ['access_token' => $token]);

        if ($res->failed() || isset($res['error'])) {
            throw new RuntimeException('Meta: '.($res['error']['message'] ?? 'request failed ('.$res->status().')'));
        }

        return $res->json();
    }

    /** Ad accounts the token can read: [act_id => "Name (CUR)"]. */
    public function adAccounts(): array
    {
        if (self::viaPilot()) {
            return collect($this->pilot('ad-accounts')['data'] ?? [])
                ->mapWithKeys(fn ($a) => [self::act($a['id']) => "{$a['name']} ({$a['currency']})"])->all();
        }

        $out = [];
        $page = $this->get('me/adaccounts', ['fields' => 'name,account_id,currency', 'limit' => 100]);
        foreach ($page['data'] ?? [] as $a) {
            $out['act_'.$a['account_id']] = "{$a['name']} ({$a['currency']})";
        }

        return $out;
    }

    /** @return array{name: string, currency: string} */
    public function account(string $id): array
    {
        if (self::viaPilot()) {
            $a = collect($this->pilot('ad-accounts')['data'] ?? [])->first(fn ($a) => self::act($a['id']) === self::act($id))
                ?? throw new RuntimeException("Ad account {$id} is not connected in Klok Pilot.");

            return ['name' => $a['name'], 'currency' => strtoupper($a['currency'])];
        }

        $a = $this->get(self::act($id), ['fields' => 'name,currency']);

        return ['name' => $a['name'] ?? $id, 'currency' => strtoupper($a['currency'] ?? '')];
    }

    /**
     * Spend per campaign per day. Only campaigns whose name contains $filter (if given).
     *
     * @return array<int, array{campaign_id: string, campaign_name: string, date: string, spend: int, impressions: int, clicks: int}>
     */
    public function dailyCampaignSpend(string $accountId, CarbonInterface $from, CarbonInterface $to, ?string $filter = null): array
    {
        if (self::viaPilot()) {
            $rows = [];
            for ($start = CarbonImmutable::parse($from->toDateString()); $start->lte($to); $start = $start->addDays(self::BRIDGE_DAYS)) {
                $end = $start->addDays(self::BRIDGE_DAYS - 1)->min($to);
                foreach ($this->pilot('ad-accounts/'.self::act($accountId).'/spend', ['from' => $start->toDateString(), 'to' => $end->toDateString()])['data'] ?? [] as $r) {
                    if (blank($filter) || str_contains(mb_strtolower($r['campaign_name']), mb_strtolower($filter))) {
                        $rows[] = array_intersect_key($r, array_flip(['campaign_id', 'campaign_name', 'date', 'spend', 'impressions', 'clicks']));
                    }
                }
            }

            return $rows;
        }

        $params = [
            'level' => 'campaign',
            'time_increment' => 1,
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks',
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'limit' => 500,
        ];
        if (filled($filter)) {
            $params['filtering'] = json_encode([['field' => 'campaign.name', 'operator' => 'CONTAIN', 'value' => $filter]]);
        }

        $rows = [];
        $page = $this->get(self::act($accountId).'/insights', $params);
        while (true) {
            foreach ($page['data'] ?? [] as $r) {
                $rows[] = [
                    'campaign_id' => (string) $r['campaign_id'],
                    'campaign_name' => (string) $r['campaign_name'],
                    'date' => (string) $r['date_start'],
                    'spend' => (int) round(((float) ($r['spend'] ?? 0)) * 100),
                    'impressions' => (int) ($r['impressions'] ?? 0),
                    'clicks' => (int) ($r['clicks'] ?? 0),
                ];
            }
            if (empty($page['paging']['next'])) {
                break;
            }
            $page = $this->get($page['paging']['next']);
        }

        return $rows;
    }

    /**
     * Account spend per month, to see where its history starts (through Klok Pilot only).
     *
     * @return array<int, array{month: string, spend: int}>
     */
    public function monthlySpend(string $accountId, CarbonInterface $from, CarbonInterface $to): array
    {
        return self::viaPilot()
            ? $this->pilot('ad-accounts/'.self::act($accountId).'/months', ['from' => $from->toDateString(), 'to' => $to->toDateString()])['data'] ?? []
            : [];
    }

    public static function act(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.preg_replace('/\D/', '', $id);
    }
}
