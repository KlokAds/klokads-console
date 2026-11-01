<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\PersonalAdAssignment;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use App\Services\AdDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each morning: yesterday's ads per owner (Tasfia and my clients), campaign by campaign, with what to do. */
class AdDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private function day(string $campaign, string $date, int $spend, int $results): array
    {
        return ['campaign_id' => $campaign, 'campaign_name' => $campaign === 'c1' ? 'Leads WA' : 'Video views', 'date' => $date, 'spend' => $spend,
            'currency' => 'SGD', 'impressions' => 1000, 'clicks' => 20, 'reach' => 800, 'results' => $results, 'result_type' => 'lead'];
    }

    public function test_tasfia_gets_yesterdays_numbers_and_tips_once_after_9am(): void
    {
        $client = Client::create(['name' => 'Tasfia', 'email' => 't@example.sg', 'company' => 'Tasfia Engineering', 'meta_ad_account_id' => 'act_1', 'currency' => 'sgd', 'meta_campaigns' => [
            'c1' => ['name' => 'Leads WA', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 1900, 'start' => '2026-10-01'],
            'c2' => ['name' => 'Video views', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 600, 'start' => '2026-10-01'],
        ]]);
        foreach (range(2, 7) as $n) {
            $date = CarbonImmutable::parse('2026-11-01')->subDays($n)->toDateString();
            MetaSpendDay::create(['client_id' => $client->id] + $this->day('c1', $date, 1900, 10));
            MetaSpendDay::create(['client_id' => $client->id] + $this->day('c2', $date, 600, 2));
        }
        // Yesterday: c1 costlier (S$6.33 a lead against S$1.90), c2 spent nothing although it is on.
        MetaSpendDay::create(['client_id' => $client->id] + $this->day('c1', '2026-10-31', 1900, 3));

        $report = app(AdDailyReport::class);
        $this->assertSame(0, $report->run(CarbonImmutable::parse('2026-11-01 08:00', 'Asia/Dhaka')));
        $this->assertSame(1, $report->run(CarbonImmutable::parse('2026-11-01 09:30', 'Asia/Dhaka')));
        $this->assertSame(0, $report->run(CarbonImmutable::parse('2026-11-01 15:00', 'Asia/Dhaka')));

        $note = AppNotification::sole();
        $this->assertStringContainsString('Tasfia Engineering · Sat 31 Oct', $note->title);
        $this->assertStringContainsString('Leads WA: S$19.00 · 3 leads', $note->body);
        $this->assertStringContainsString('got costlier', $note->body);
        $this->assertStringContainsString('“Video views” is on but spent nothing', $note->body);
    }

    public function test_my_clients_ad_account_gets_its_own_report(): void
    {
        $client = PersonalClient::create(['name' => 'GFFS', 'ad_campaigns' => ['p1' => ['name' => 'Shop sales', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => 1000, 'start' => '2026-10-01']]]);
        PersonalAdAssignment::create(['personal_client_id' => $client->id, 'account_id' => 'act_9', 'currency' => 'USD', 'from_date' => '2026-10-01']);
        PersonalAdDay::create(['personal_client_id' => $client->id, 'account_id' => 'act_9', 'campaign_id' => 'p1', 'campaign_name' => 'Shop sales', 'date' => '2026-10-31',
            'currency' => 'USD', 'spend' => 1000, 'results' => 4, 'result_type' => 'purchase', 'reach' => 900]);

        $this->assertSame(1, app(AdDailyReport::class)->run(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka')));

        $note = AppNotification::sole();
        $this->assertStringContainsString('GFFS', $note->title);
        $this->assertStringContainsString('Shop sales: $10.00 · 4', $note->body);
        $this->assertStringContainsString('used its whole', $note->body);
    }

    public function test_no_ads_no_report(): void
    {
        Client::create(['name' => 'Quiet', 'email' => 'q@example.sg', 'meta_ad_account_id' => 'act_2', 'meta_campaigns' => ['x' => ['name' => 'Old', 'status' => 'ACTIVE', 'start' => '2025-01-01']]]);

        $this->assertSame(0, app(AdDailyReport::class)->run(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka')));
    }
}
