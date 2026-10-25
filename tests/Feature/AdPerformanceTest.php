<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdPerformance;
use App\Services\PrepaidBalance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Results, cost per result, campaign status, suggestions and the Monday summary. */
class AdPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        Setting::put('commission_rate', '20');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Dhaka')); // a Monday
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Dhaka'));
        $this->client = Client::create(['name' => 'Tasfia Engineering', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com',
            'meta_ad_account_id' => 'act_808', 'billing_mode' => 'prepaid', 'currency' => 'sgd', 'spend_billed_until' => '2026-10-25',
            'meta_campaigns' => ['c1' => ['name' => 'Painting Leads', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 1000],
                'c2' => ['name' => 'Handyman Traffic', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 2000]]]);
    }

    /** $days: date => [campaign => [spend, results]] */
    private function spend(array $days): void
    {
        foreach ($days as $date => $campaigns) {
            foreach ($campaigns as $id => [$spend, $results]) {
                MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => $id, 'campaign_name' => $id === 'c1' ? 'Painting Leads' : 'Handyman Traffic',
                    'date' => $date, 'spend' => $spend, 'results' => $results, 'result_type' => 'lead', 'impressions' => 1000, 'clicks' => 20, 'currency' => 'SGD']);
            }
        }
    }

    /** Last week: Painting brings leads at S$2.50 and uses its whole budget; Handyman costs S$10 a lead. */
    private function aWeek(): void
    {
        foreach (range(1, 7) as $ago) {
            $this->spend([CarbonImmutable::today()->subDays($ago)->toDateString() => ['c1' => [1000, 4], 'c2' => [2000, 2]]]);
        }
    }

    public function test_results_and_cost_per_result_per_campaign_and_on_the_ad_money_page(): void
    {
        $this->aWeek();
        $rows = collect(AdPerformance::table($this->client, CarbonImmutable::today()->subDays(7), CarbonImmutable::today()));

        $painting = $rows->firstWhere('id', 'c1');
        $this->assertSame(28, $painting['results']);
        $this->assertSame('leads', $painting['result_label']);
        $this->assertSame(250, $painting['cost_per_result']);
        $this->assertSame(1000, $painting['daily_budget']);
        $this->assertSame(1000, $rows->firstWhere('id', 'c2')['cost_per_result']);

        $this->get('/ad-money?period=last7')->assertInertia(fn ($page) => $page->has('campaigns', 2)->where('campaigns.0.status', 'ACTIVE')->has('suggestions'));
    }

    public function test_suggestions_move_budget_to_the_cheaper_campaign_and_raise_the_one_held_back(): void
    {
        $this->aWeek();
        $this->spend([CarbonImmutable::today()->subDays(2)->toDateString() => ['c3' => [1500, 0]]]);
        $texts = collect(AdPerformance::suggestions($this->client))->pluck('text')->implode("\n");

        $this->assertStringContainsString('“Handyman Traffic” costs', $texts);
        $this->assertStringContainsString('Move some budget from it to “Painting Leads”', $texts);
        $this->assertStringContainsString('“Painting Leads” gets the cheapest results', $texts);
        $this->assertStringContainsString('with no leads', $texts);
    }

    public function test_the_daily_notification_says_what_the_spend_bought(): void
    {
        $this->spend(['2026-10-26' => ['c1' => [1000, 4], 'c2' => [2000, 2]]]);
        $this->client->update(['spend_billed_until' => '2026-10-25']);
        app(PrepaidBalance::class)->deduct($this->client->fresh());

        $body = AppNotification::where('title', 'like', '📊 Tasfia Engineering · 26 Oct')->value('body');
        $this->assertStringContainsString('6 leads at SGD 5.00 each', $body);
    }

    public function test_a_campaign_rejected_or_paused_is_told_the_first_look_only_remembers(): void
    {
        Http::fake(['pilot.test/internal/console/ad-accounts/act_808/campaigns' => Http::sequence()
            ->push(['data' => [['id' => 'c1', 'name' => 'Painting Leads', 'status' => 'ACTIVE', 'objective' => null, 'daily_budget' => 1000]]])
            ->push(['data' => [['id' => 'c1', 'name' => 'Painting Leads', 'status' => 'DISAPPROVED', 'objective' => null, 'daily_budget' => 1000]]])]);
        $this->client->update(['meta_campaigns' => null]);
        $performance = app(AdPerformance::class);

        $performance->watchCampaigns($this->client->fresh());
        $this->assertSame(0, AppNotification::count());
        $performance->watchCampaigns($this->client->fresh());

        $this->assertSame('Tasfia Engineering: “Painting Leads” ⛔ rejected by Meta', AppNotification::sole()->title);
        $this->assertSame('DISAPPROVED', $this->client->fresh()->meta_campaigns['c1']['status']);
    }

    public function test_a_cost_jump_and_a_campaign_that_stopped_spending_are_flagged_once(): void
    {
        $this->aWeek();
        // Yesterday: Painting S$12 for 1 lead (it was S$2.50); Handyman spent nothing though it ran every day.
        MetaSpendDay::where('date', CarbonImmutable::yesterday()->toDateString())->delete();
        $this->spend([CarbonImmutable::yesterday()->toDateString() => ['c1' => [1200, 1]]]);
        $performance = app(AdPerformance::class);

        $performance->checkYesterday($this->client, CarbonImmutable::now());
        $performance->checkYesterday($this->client, CarbonImmutable::now());

        $this->assertSame(1, AppNotification::where('title', '📈 Tasfia Engineering: “Painting Leads” got costlier')->count());
        $this->assertSame(1, AppNotification::where('title', '🛑 Tasfia Engineering: “Handyman Traffic” stopped spending')->count());
        $this->assertStringContainsString('S$12.00 per lead', AppNotification::where('title', 'like', '%got costlier')->value('body'));
    }

    public function test_monday_summary_once_with_last_week_against_the_week_before_and_tips(): void
    {
        $this->aWeek();
        $performance = app(AdPerformance::class);
        $performance->weekly($this->client, CarbonImmutable::now());
        $performance->weekly($this->client, CarbonImmutable::now());
        $performance->weekly($this->client, CarbonImmutable::now()->addDay()); // Tuesday: nothing

        $note = AppNotification::where('title', '🗓 Tasfia Engineering · week of 26 Oct')->sole();
        $this->assertStringContainsString('Spent S$210.00', $note->body);
        $this->assertStringContainsString('42 leads', $note->body);
        $this->assertStringContainsString('💡', $note->body);
    }
}
