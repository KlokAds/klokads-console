<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\PersonalAdAssignment;
use App\Models\PersonalClient;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdAdvice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every ad issue and recommendation, told clearly and once, to the right client. */
class AdAdviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Dhaka'));
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Dhaka'));
    }

    public function test_metas_recommendations_and_issues_go_to_whose_campaign_it_is_once(): void
    {
        $marine = PersonalClient::create(['name' => 'Marine Orvion']);
        PersonalAdAssignment::create(['personal_client_id' => $marine->id, 'account_id' => 'act_2', 'account_name' => 'Eazy Mall', 'currency' => 'USD', 'campaign_id' => '21', 'paid_by' => 'client', 'from_date' => '2026-10-01']);
        Http::fake([
            'pilot.test/internal/console/ad-accounts/act_2/advice' => Http::response(['data' => [
                ['kind' => 'recommendation', 'type' => 'BUDGET_LIMITED', 'objects' => ['21'], 'title' => 'Get 20% more results', 'body' => 'Raise the budget.', 'lift' => '3', 'url' => 'https://adsmanager.facebook.com/x'],
                ['kind' => 'issue', 'type' => 'LEARNING_LIMITED', 'objects' => ['21'], 'title' => 'Learning limited', 'body' => 'Too few results to learn.', 'lift' => null, 'url' => null],
                ['kind' => 'recommendation', 'type' => 'BUDGET_LIMITED', 'objects' => ['99'], 'title' => 'Not theirs', 'body' => 'Someone else’s campaign.', 'lift' => null, 'url' => null],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/campaigns' => Http::response(['data' => [['id' => '21', 'name' => 'Trio Msg', 'status' => 'ACTIVE', 'objective' => null, 'daily_budget' => 1000]]]),
        ]);
        $advice = app(AdAdvice::class);

        $this->assertSame(2, $advice->fromMeta());
        $this->assertSame(0, $advice->fromMeta(), 'once, not every 6 hours');

        $budget = AppNotification::where('title', 'Marine Orvion: 💰 Budget limited')->sole();
        $this->assertStringContainsString('Get 20% more results', $budget->body);
        $this->assertStringContainsString('Campaign: Trio Msg', $budget->body);
        $this->assertSame(1, AppNotification::where('title', 'Marine Orvion: 📚 Learning limited')->count());
        $this->assertCount(2, AdAdvice::forOwner('Marine Orvion'), 'shown on their page too');
    }

    public function test_the_numbers_show_creative_fatigue_and_a_budget_held_back(): void
    {
        $tasfia = Client::create(['name' => 'Tasfia Engineering', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com', 'meta_ad_account_id' => 'act_808',
            'billing_mode' => 'prepaid', 'currency' => 'sgd',
            'meta_campaigns' => ['1' => ['name' => 'Painting Leads', 'status' => 'ACTIVE', 'daily_budget' => 1000], '2' => ['name' => 'Handyman Traffic', 'status' => 'ACTIVE', 'daily_budget' => 5000]]]);
        foreach (range(1, 8) as $ago) {
            $date = CarbonImmutable::parse('2026-10-25')->subDays($ago - 1)->toDateString();
            // Painting: whole budget every day, cheap results. Handyman: clicks collapse in the last 3 days.
            MetaSpendDay::create(['client_id' => $tasfia->id, 'campaign_id' => '1', 'campaign_name' => 'Painting Leads', 'date' => $date, 'spend' => 1000, 'results' => 5, 'impressions' => 1000, 'clicks' => 20, 'currency' => 'SGD']);
            MetaSpendDay::create(['client_id' => $tasfia->id, 'campaign_id' => '2', 'campaign_name' => 'Handyman Traffic', 'date' => $date, 'spend' => 3000, 'results' => 3,
                'impressions' => 2000, 'clicks' => $ago <= 3 ? 10 : 60, 'currency' => 'SGD']);
        }

        app(AdAdvice::class)->fromNumbers(CarbonImmutable::now()->setTimezone('Asia/Dhaka'));

        $this->assertSame(1, AppNotification::where('title', 'Tasfia Engineering: 🎨 Creative fatigue — “Handyman Traffic”')->count());
        $this->assertSame(1, AppNotification::where('title', 'Tasfia Engineering: 💰 Budget limited — “Painting Leads”')->count());
        $this->assertSame(0, AppNotification::where('title', 'like', '%Budget limited — “Handyman%')->count(), 'costly, not held back');
    }
}
