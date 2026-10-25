<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\PersonalAdBill;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdPerformance;
use App\Services\PersonalAds;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Edge Education's and Marine Orvion's ads: whose campaigns, spend in their currency, controls, and bills that never overlap. */
class PersonalAdsTest extends TestCase
{
    use RefreshDatabase;

    private PersonalClient $edge;

    private PersonalClient $marine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        CarbonImmutable::setTestNow('2026-11-01 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->edge = PersonalClient::create(['name' => 'Edge Education']);
        $this->marine = PersonalClient::create(['name' => 'Marine Orvion']);
    }

    private function fakePilot(string $edgeStatus = 'ACTIVE'): void
    {
        Http::fake([
            'pilot.test/internal/console/ad-accounts' => Http::response(['data' => [
                ['id' => 'act_1', 'name' => 'Edge Meta Account', 'currency' => 'BDT', 'client' => 'Edge'],
                ['id' => 'act_2', 'name' => 'Eazy Mall', 'currency' => 'USD', 'client' => 'Eazy Mall'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_1/campaigns' => Http::response(['data' => [
                ['id' => '11', 'name' => 'Edge Leads', 'status' => $edgeStatus, 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 150000, 'start' => '2026-10-01', 'end' => '2026-11-30'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/campaigns' => Http::response(['data' => [
                ['id' => '21', 'name' => 'Marine Orvion Awareness', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_AWARENESS', 'daily_budget' => 1000, 'start' => null, 'end' => null],
                ['id' => '22', 'name' => 'Eazy Mall own', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => 500, 'start' => null, 'end' => null],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_1/spend*' => Http::response(['data' => [
                ['campaign_id' => '11', 'campaign_name' => 'Edge Leads', 'date' => '2026-10-10', 'spend' => 120000, 'impressions' => 9000, 'clicks' => 200, 'reach' => 7000, 'results' => 12, 'result_type' => 'lead'],
                ['campaign_id' => '11', 'campaign_name' => 'Edge Leads', 'date' => '2026-10-20', 'spend' => 80000, 'impressions' => 6000, 'clicks' => 100, 'reach' => 5000, 'results' => 8, 'result_type' => 'lead'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/spend*' => Http::response(['data' => [
                ['campaign_id' => '21', 'campaign_name' => 'Marine Orvion Awareness', 'date' => '2026-10-15', 'spend' => 1500, 'impressions' => 3000, 'clicks' => 9, 'reach' => 2500, 'results' => 1800, 'result_type' => 'video_view'],
                ['campaign_id' => '22', 'campaign_name' => 'Eazy Mall own', 'date' => '2026-10-15', 'spend' => 999, 'impressions' => 10, 'clicks' => 1, 'reach' => 10, 'results' => 0, 'result_type' => null],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/campaigns/21/limits' => Http::response(['data' => ['active' => false, 'daily_budget' => 1000, 'end' => null, 'extendable' => true, 'why_not' => null]]),
        ]);
    }

    private function assignBoth(): void
    {
        $this->post("/personal/client-ads/{$this->edge->id}/assign", ['account_id' => 'act_1', 'whole' => true, 'paid_by' => 'me', 'from' => '2026-10-01'])->assertSessionHas('success');
        $this->post("/personal/client-ads/{$this->marine->id}/assign", ['account_id' => 'act_2', 'whole' => false, 'campaigns' => ['21'], 'paid_by' => 'me', 'from' => '2026-10-01'])->assertSessionHas('success');
    }

    public function test_a_whole_account_or_only_some_campaigns_belong_to_a_client_in_its_own_currency(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->assertSame(200000, (int) PersonalAdDay::where('personal_client_id', $this->edge->id)->sum('spend'));
        $this->assertSame(['BDT'], PersonalAdDay::where('personal_client_id', $this->edge->id)->pluck('currency')->unique()->values()->all());
        $marine = PersonalAdDay::where('personal_client_id', $this->marine->id)->get();
        $this->assertSame(['21'], $marine->pluck('campaign_id')->unique()->values()->all(), 'the account’s other campaign is not Marine’s');
        $this->assertSame('USD', $marine->first()->currency);

        // A campaign already someone's can't be given to another client.
        $this->post("/personal/client-ads/{$this->edge->id}/assign", ['account_id' => 'act_2', 'whole' => false, 'campaigns' => ['21'], 'paid_by' => 'me', 'from' => '2026-10-01'])
            ->assertSessionHas('error');
    }

    public function test_results_follow_the_campaign_goal(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->get("/personal/client-ads/{$this->marine->id}?period=lastmonth")->assertInertia(fn ($page) => $page->component('Personal/AdClients/Show')
            ->where('campaigns.0.goal', 'Awareness')->where('campaigns.0.results', 2500)->where('campaigns.0.result_label', 'people reached')
            ->where('campaigns.0.cost_per_result', 600)->where('campaigns.0.per', 'per 1,000 reached')->where('money.USD.my_card', 1500));
        $this->get("/personal/client-ads/{$this->edge->id}?period=lastmonth")->assertInertia(fn ($page) => $page
            ->where('campaigns.0.goal', 'Leads')->where('campaigns.0.results', 20)->where('campaigns.0.per', 'per lead')->where('campaigns.0.cost_per_result', 10000));
        $this->assertSame('Messages', AdPerformance::goal('OUTCOME_ENGAGEMENT', 'onsite_conversion.messaging_conversation_started_7d'));
    }

    public function test_bills_take_only_days_not_billed_before_so_nothing_is_billed_twice(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $this->edge->update(['ad_billed_until' => '2026-10-15']); // settled up to 15 Oct, from my sheet

        // 2 Nov: the unbilled days of last month → Edge 16–31 Oct (only the 20 Oct spend), Marine in USD.
        $this->assertSame(0, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka')), 'not on the 1st: Meta still corrects');
        $this->assertSame(2, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Dhaka')));
        $bill = PersonalAdBill::where('personal_client_id', $this->edge->id)->sole();
        $this->assertSame(['2026-10-16', '2026-10-31'], [$bill->period_from->toDateString(), $bill->period_to->toDateString()]);
        $this->assertSame(80000, $bill->invoice->total);
        $this->assertSame('BDT', $bill->invoice->currency);
        $this->assertNull($bill->invoice->sent_at, 'drafted, not sent');
        $this->assertSame('USD', PersonalAdBill::where('personal_client_id', $this->marine->id)->sole()->currency);

        // Again, or "Bill now" up to today: nothing new — those days are billed.
        $this->assertSame(0, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Dhaka')));
        $this->post("/personal/client-ads/{$this->edge->id}/bill", ['to' => '2026-11-01'])->assertSessionHas('error'); // today is not finished
        $this->assertSame(2, PersonalInvoice::count());

        $ledger = PersonalAds::ledger($this->edge->fresh());
        $this->assertSame(80000, $ledger['totals']['BDT']['due']);
        $this->assertSame('2026-11-01', $ledger['unbilled_from']);
    }

    public function test_campaign_controls_go_to_meta_and_the_page_shows_the_new_state(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->post("/personal/client-ads/{$this->marine->id}/campaigns/21", ['action' => 'pause'])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/campaigns/21/limits') && $r['active'] === false && $r->hasHeader('X-Console-Key', 'bridge-key'));
        $this->assertSame('PAUSED', $this->marine->fresh()->ad_campaigns[21]['status']);
        $this->post("/personal/client-ads/{$this->marine->id}/campaigns/22", ['action' => 'pause'])->assertNotFound();
    }

    public function test_the_client_report_link_shows_their_campaigns_and_nothing_about_my_card(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $token = PersonalAds::token($this->edge->fresh());
        auth()->logout();

        $this->get("/ads/{$token}?period=lastmonth")->assertOk()->assertInertia(fn ($page) => $page->component('Personal/AdClients/Report')
            ->where('name', 'Edge Education')->where('totals.BDT.spend', 200000)->where('campaigns.0.results', 20)
            ->where('campaigns.0', fn ($c) => ! isset($c['paid_by']) && ! isset($c['account'])));
        $this->get('/ads/wrong-token')->assertNotFound();
    }

    public function test_a_change_in_a_campaigns_state_is_told(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $snapshot = $this->edge->fresh()->ad_campaigns;
        $snapshot[11]['status'] = 'PAUSED'; // as it was before; Meta now says ACTIVE
        $this->edge->update(['ad_campaigns' => $snapshot]);

        app(PersonalAds::class)->sync(force: true);

        $this->assertSame(1, AppNotification::where('title', 'Edge Education: “Edge Leads” ▶️ running again')->count());
    }
}
