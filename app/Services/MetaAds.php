<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only Meta Marketing API calls with the System User token from Settings. */
class MetaAds
{
    public const VERSION = 'v26.0';

    public static function configured(): bool
    {
        return (bool) Setting::get('meta_token');
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

    public static function act(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.preg_replace('/\D/', '', $id);
    }
}
