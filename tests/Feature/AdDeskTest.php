<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tasfia's ad desk on the business dashboard: money now, every campaign, run from there. */
class AdDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_shows_the_big_clients_campaigns_and_runs_them_on_meta(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        CarbonImmutable::setTestNow('2026-10-25 10:00');
        $client = Client::create(['name' => 'Tasfia Engineering', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com',
            'meta_ad_account_id' => 'act_808', 'billing_mode' => 'prepaid', 'currency' => 'sgd',
            'meta_campaigns' => ['11' => ['name' => 'Handyman Traffic', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => 500, 'end' => null],
                '12' => ['name' => 'Painting Leads', 'status' => 'PAUSED', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 1000, 'end' => null]]]);
        MetaSpendDay::create(['client_id' => $client->id, 'campaign_id' => '11', 'campaign_name' => 'Handyman Traffic', 'date' => '2026-10-24', 'spend' => 500,
            'results' => 40, 'result_type' => 'link_click', 'impressions' => 2000, 'clicks' => 45, 'currency' => 'SGD']);

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('adDesk.client.id', $client->id)
            ->has('adDesk.campaigns', 2)->where('adDesk.campaigns.0.name', 'Handyman Traffic')->where('adDesk.campaigns.1.status', 'PAUSED'));

        Http::fake(['pilot.test/internal/console/ad-accounts/act_808/campaigns/12/limits' => Http::response(['data' => ['active' => true, 'daily_budget' => 1000, 'end' => null, 'extendable' => true, 'why_not' => null]])]);
        $this->post("/clients/{$client->id}/campaigns/12", ['action' => 'resume'])->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['active'] === true);
        $this->assertSame('ACTIVE', $client->fresh()->meta_campaigns[12]['status']);
        $this->post("/clients/{$client->id}/campaigns/99", ['action' => 'pause'])->assertNotFound();

        // The full ad desk, and each campaign in full (the same page as my own clients').
        $this->get('/ad-desk?tab=campaigns')->assertInertia(fn ($page) => $page->component('AdDesk/Index')->where('tab', 'campaigns')
            ->has('campaigns', 2)->where('spent.spend', 500));
        $this->get("/clients/{$client->id}/campaigns/11?period=all")->assertInertia(fn ($page) => $page->component('Personal/AdClients/Campaign')
            ->where('business', true)->where('campaign.name', 'Handyman Traffic')->where('totals.spend', 500)->where('totals.results', 40));
    }
}
