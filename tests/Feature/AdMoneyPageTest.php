<?php

namespace Tests\Feature;

use App\Models\AirwallexEntry;
use App\Models\ChargeRequest;
use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\MetaSpendDay;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdMoneyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_page_shows_balance_airwallex_spend_charges_payouts_and_every_movement(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        CarbonImmutable::setTestNow('2026-10-25 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
        Setting::put('airwallex_client_id', 'cid');
        Setting::put('airwallex_api_key', 'key');
        Setting::put('airwallex_balances', json_encode(['SGD' => 20352, 'USD' => 33]));
        $client = Client::create(['name' => 'Tasfia Engineering', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com',
            'meta_ad_account_id' => 'act_808', 'billing_mode' => 'prepaid', 'currency' => 'sgd', 'spend_billing' => true]);

        ClientBalanceEntry::create(['client_id' => $client->id, 'kind' => 'manual', 'amount' => 20352, 'date' => '2026-10-25', 'note' => 'Opening balance']);
        ClientBalanceEntry::create(['client_id' => $client->id, 'kind' => 'spend', 'amount' => -6000, 'spend' => 5000, 'commission' => 1000, 'date' => '2026-10-24', 'note' => '1 campaign']);
        MetaSpendDay::create(['client_id' => $client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Leads', 'date' => '2026-10-24', 'spend' => 5000, 'currency' => 'SGD']);
        MetaSpendDay::create(['client_id' => $client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Leads', 'date' => '2026-09-10', 'spend' => 9999, 'currency' => 'SGD']);
        AirwallexEntry::create(['airwallex_id' => 'it:1', 'kind' => 'meta', 'amount' => -3757, 'currency' => 'SGD', 'description' => 'FACEBK *NRM', 'occurred_at' => '2026-10-16 06:00', 'client_id' => $client->id]);
        AirwallexEntry::create(['airwallex_id' => 'ft:2', 'kind' => 'payout', 'amount' => -100000, 'currency' => 'USD', 'description' => 'Pay 1000.00 USD to Md Mujaid Hossain', 'occurred_at' => '2026-10-19 06:00']);
        Payout::create(['stripe_payout_id' => 'po_1', 'amount' => 50000, 'currency' => 'sgd', 'status' => 'in_transit', 'arrival_at' => '2026-10-28', 'occurred_at' => '2026-10-24']);
        $waiting = ChargeRequest::create(['client_id' => $client->id, 'amount' => 84000, 'suggested' => 84000, 'currency' => 'SGD', 'description' => 'Top-up']);

        $this->get('/ad-money')->assertInertia(fn ($page) => $page->component('AdMoney/Index')
            ->where('client.id', $client->id)
            ->where('now.balance', 14352)
            ->where('now.airwallex', 20352)
            ->where('now.on_the_way', 50000)
            ->where('now.waiting.id', $waiting->id)
            ->where('totals.spend', 5000)
            ->where('totals.commission', 1000)
            ->where('totals.topped_up', 20352)
            ->where('totals.meta_charged', 3757)
            ->where('days.23.spend', 5000)
            ->where('events', fn ($events) => collect($events)->pluck('where')->unique()->sort()->values()->all() === ['airwallex', 'approval', 'balance', 'stripe']
                && collect($events)->contains(fn ($e) => str_contains($e['text'], 'Md Mujaid Hossain'))));

        $this->get('/ad-money?period=2026-09')->assertInertia(fn ($page) => $page->where('range.period', '2026-09')->where('range.label', 'September 2026')->where('totals.spend', 9999));

        // My own dates: both months together, shown per day; a long range is shown per month.
        $this->get('/ad-money?period=custom&from=2026-09-01&to=2026-10-25')->assertInertia(fn ($page) => $page
            ->where('range.label', '1 Sep – 25 Oct 2026')->where('totals.spend', 14999)->where('range.by_month', false)->has('days', 55));
        $this->get('/ad-money?period=year')->assertInertia(fn ($page) => $page
            ->where('range.by_month', true)->has('days', 10)->where('days.8.spend', 9999)->where('days.8.label', 'Sep 2026'));
        $this->get('/ad-money?period=last7')->assertInertia(fn ($page) => $page->has('days', 7)->where('totals.spend', 5000));
    }
}
