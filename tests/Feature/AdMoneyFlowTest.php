<?php

namespace Tests\Feature;

use App\Models\AirwallexEntry;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Services\Airwallex;
use App\Services\MetaAds;
use App\Services\SpendBilling;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tasfia's money flow: spend from Klok Pilot, history, Stripe payout → Airwallex, Meta charges the card. */
class AdMoneyFlowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        CarbonImmutable::setTestNow('2026-10-25 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        $this->client = Client::create([
            'name' => 'Hor', 'company' => 'Tasfia Engineering', 'email' => 'hor@example.sg', 'meta_ad_account_id' => 'act_808',
            'billing_mode' => 'prepaid', 'prepaid_buffer_days' => 7, 'prepaid_topup_days' => 14, 'prepaid_min_topup' => 50000,
        ]);
    }

    private function fakePilot(): void
    {
        Http::fake([
            'pilot.test/internal/console/ad-accounts' => Http::response(['data' => [['id' => 'act_808', 'name' => 'Tasfia Engineering', 'currency' => 'SGD', 'client' => 'Tasfia']]]),
            'pilot.test/internal/console/ad-accounts/act_808/months*' => Http::response(['data' => [
                ['month' => '2026-08', 'spend' => 0], ['month' => '2026-09', 'spend' => 90000], ['month' => '2026-10', 'spend' => 40000],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_808/spend*' => function (Request $request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

                return Http::response(['data' => [
                    ['campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads', 'date' => $q['from'], 'spend' => 3000, 'impressions' => 900, 'clicks' => 12],
                    ['campaign_id' => 'c2', 'campaign_name' => 'Other brand', 'date' => $q['to'], 'spend' => 1000, 'impressions' => 100, 'clicks' => 1],
                ]]);
            },
        ]);
    }

    public function test_spend_comes_from_klok_pilot_with_the_key_and_charges_in_the_accounts_currency(): void
    {
        $this->fakePilot();
        $this->assertTrue(MetaAds::configured());
        $this->assertSame(['act_808' => 'Tasfia Engineering (SGD)'], app(MetaAds::class)->adAccounts());

        // 50 days are asked for in 31-day steps; the campaign filter still applies.
        $rows = app(MetaAds::class)->dailyCampaignSpend('act_808', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-20'), 'tasfia');
        $this->assertSame(['2026-09-01', '2026-10-02'], array_column($rows, 'date'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Console-Key', 'bridge-key'));

        app(SpendBilling::class)->sync($this->client);
        $this->assertSame('sgd', $this->client->fresh()->currency);
    }

    public function test_full_history_is_imported_step_by_step_and_never_billed(): void
    {
        $this->fakePilot();
        $billing = app(SpendBilling::class);

        $this->post("/clients/{$this->client->id}/meta/history")->assertSessionHas('success');
        $client = $this->client->fresh();
        $this->assertSame('2026-09-01', $client->history_from->toDateString(), 'the first month that spent');
        $this->assertSame('2026-10-24', $client->spend_billed_until->toDateString(), 'billing starts from now');
        $this->assertSame('2026-09-01', $client->history_cursor->toDateString(), 'one 31-day step already in');

        $this->assertNull($billing->importHistory($client), 'nothing left to import');
        $this->assertSame(1, AppNotification::where('title', 'like', '📚 Tasfia Engineering%')->count());
        $this->assertGreaterThan(0, MetaSpendDay::where('client_id', $client->id)->count());
        $this->assertCount(0, $billing->unbilled($client), 'history is a record, not a bill');
    }

    private function fakeAirwallex(array $financial, array $issuing, array $balances = [['currency' => 'SGD', 'available_amount' => 120.5]]): void
    {
        Setting::put('airwallex_client_id', 'cid');
        Setting::put('airwallex_api_key', 'key');
        Http::fake([
            'api.airwallex.com/api/v1/authentication/login' => Http::response(['token' => 'tok']),
            'api.airwallex.com/api/v1/financial_transactions*' => Http::response(['items' => $financial, 'has_more' => false]),
            'api.airwallex.com/api/v1/issuing/transactions*' => Http::response(['items' => $issuing, 'has_more' => false]),
            'api.airwallex.com/api/v1/balances/current' => Http::response($balances),
        ]);
    }

    public function test_a_stripe_payout_landing_in_airwallex_and_meta_charging_the_card(): void
    {
        $payout = Payout::create(['stripe_payout_id' => 'po_1', 'amount' => 100000, 'currency' => 'sgd', 'status' => 'paid',
            'arrival_at' => '2026-10-24 00:00', 'occurred_at' => '2026-10-22 00:00']);
        MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads', 'date' => '2026-10-20', 'spend' => 4550, 'currency' => 'SGD']);

        $this->fakeAirwallex(
            [['id' => 'ft_1', 'amount' => 999.4, 'currency' => 'SGD', 'transaction_type' => 'DEPOSIT', 'description' => 'STRIPE PAYMENTS', 'status' => 'SETTLED', 'created_at' => '2026-10-24T03:00:00+0000'],
                ['id' => 'ft_2', 'amount' => -45.5, 'currency' => 'SGD', 'transaction_type' => 'ISSUING_CAPTURE', 'created_at' => '2026-10-24T05:00:00+0000']],
            [['transaction_id' => 'it_1', 'transaction_type' => 'AUTHORIZATION', 'billing_amount' => 45.5, 'billing_currency' => 'SGD', 'merchant' => ['name' => 'FACEBK *ABC123']],
                ['transaction_id' => 'it_2', 'transaction_type' => 'CLEARING', 'billing_amount' => 45.5, 'billing_currency' => 'SGD', 'merchant' => ['name' => 'FACEBK *ABC123', 'city' => 'fb.me/ads'], 'posted_date' => '2026-10-24T06:00:00+0000']],
        );

        $this->assertSame(['new' => 2], app(Airwallex::class)->run(force: true));

        $deposit = AirwallexEntry::where('kind', 'deposit')->sole();
        $this->assertSame($payout->id, $deposit->payout_id, 'matched to the Stripe payout (less a small fee)');
        $meta = AirwallexEntry::where('kind', 'meta')->sole();
        $this->assertSame(-4550, $meta->amount, 'the authorisation is not counted twice');
        $this->assertSame($this->client->id, $meta->client_id);

        $titles = AppNotification::pluck('title')->all();
        $this->assertContains('🏦 Stripe payout arrived in Airwallex', $titles);
        $this->assertContains('💳 Meta charged the Airwallex card S$45.50', $titles);

        $this->get('/payouts')->assertInertia(fn ($page) => $page->where('payouts.data.0.in_airwallex', fn ($at) => $at !== null)->where('airwallex.balances.SGD', 12050));

        // Seen again on the next run: nothing new, no second notification.
        $this->assertSame(['new' => 0], app(Airwallex::class)->run(force: true));
    }

    public function test_warns_when_airwallex_will_not_cover_a_week_of_meta_and_when_a_payout_is_late(): void
    {
        foreach (range(1, 7) as $ago) {
            MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads',
                'date' => CarbonImmutable::today()->subDays($ago)->toDateString(), 'spend' => 5000, 'currency' => 'SGD']);
        }
        Payout::create(['stripe_payout_id' => 'po_late', 'amount' => 50000, 'currency' => 'sgd', 'status' => 'paid',
            'arrival_at' => '2026-10-18 00:00', 'occurred_at' => '2026-10-15 00:00']);
        $this->fakeAirwallex([], [], [['currency' => 'SGD', 'available_amount' => 100]]);

        app(Airwallex::class)->run(force: true);
        app(Airwallex::class)->run(force: true);

        $this->assertSame(1, AppNotification::where('title', '⚠️ Airwallex low for Meta')->count(), 'once a day');
        $this->assertStringContainsString('S$350.00', AppNotification::where('title', '⚠️ Airwallex low for Meta')->value('body'));
        $this->assertSame(1, AppNotification::where('title', '⏳ Stripe payout not in Airwallex yet')->count());
    }

    public function test_a_bad_airwallex_key_is_not_kept(): void
    {
        Http::fake(['*airwallex.com/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->post('/settings/airwallex', ['client_id' => 'cid', 'api_key' => 'wrong'])->assertSessionHas('error');
        $this->assertNull(Setting::get('airwallex_api_key'));
        $this->assertFalse(Airwallex::configured());
    }
}
