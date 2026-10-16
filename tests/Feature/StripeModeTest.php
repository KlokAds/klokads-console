<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowReport;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeModeTest extends TestCase
{
    use RefreshDatabase;

    private function charge(int $amount): Transaction
    {
        return Transaction::create([
            'stripe_id' => 'txn_'.uniqid(),
            'type' => 'charge',
            'reporting_category' => 'charge',
            'amount' => $amount,
            'fee' => 0,
            'net' => $amount,
            'currency' => 'usd',
            'occurred_at' => now(),
        ]);
    }

    public function test_test_data_is_hidden_once_a_live_key_is_connected(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->charge(10000);
        $this->assertFalse(Transaction::first()->livemode);
        $this->assertSame(10000, CashflowReport::forPeriod('this_month')->summary()['gross']);

        Setting::put('stripe_secret', 'sk_live_abc123');
        $this->assertTrue(StripeService::isLive());
        $this->assertSame(0, CashflowReport::forPeriod('this_month')->summary()['gross']);
        $this->assertSame(0, Transaction::count());

        $this->charge(25000);
        $this->assertSame(25000, CashflowReport::forPeriod('this_month')->summary()['gross']);

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->assertSame(10000, CashflowReport::forPeriod('this_month')->summary()['gross']);
        $this->assertSame(2, Transaction::withoutGlobalScope('stripe_mode')->count());
    }

    public function test_the_last_mode_is_kept_after_disconnecting(): void
    {
        Setting::put('stripe_secret', null);
        Setting::put('stripe_mode', 'live');

        $this->assertTrue(StripeService::isLive());
    }

    public function test_receivables_and_client_totals_respect_the_mode(): void
    {
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg']);
        Setting::put('stripe_secret', 'sk_test_abc123');
        Invoice::create(['stripe_invoice_id' => 'in_test', 'client_id' => $client->id, 'currency' => 'usd', 'status' => 'open', 'total' => 500, 'amount_remaining' => 500]);

        $this->assertSame(500, CashflowReport::receivables()['outstanding']);

        Setting::put('stripe_secret', 'sk_live_abc123');
        $this->assertSame(0, CashflowReport::receivables()['outstanding']);
        $this->assertSame(0, (int) Client::withSum(['invoices as owed' => fn ($q) => $q->where('status', 'open')], 'amount_remaining')->first()->owed);
    }

    public function test_clients_keep_separate_test_and_live_customers(): void
    {
        $client = Client::create([
            'name' => 'Bo', 'email' => 'bo@example.sg',
            'stripe_test_customer_id' => 'cus_test_1', 'stripe_customer_id' => 'cus_live_1',
        ]);

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->assertSame('cus_test_1', $client->stripeCustomerId());

        Setting::put('stripe_secret', 'sk_live_abc123');
        $this->assertSame('cus_live_1', $client->stripeCustomerId());

        $this->assertTrue(Client::findByStripeCustomer('cus_test_1')->is($client));
        $this->assertTrue(Client::findByStripeCustomer('cus_live_1')->is($client));
    }

    public function test_currency_cannot_be_changed_by_hand_while_stripe_is_connected(): void
    {
        $user = User::factory()->create();
        Setting::put('currency', 'usd');

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->actingAs($user)->post('/settings/business', ['name' => 'Klok Ads', 'currency' => 'SGD'])->assertSessionHasNoErrors();
        $this->assertSame('usd', Setting::get('currency'));

        Setting::put('stripe_secret', null);
        $this->actingAs($user)->post('/settings/business', ['name' => 'Klok Ads', 'currency' => 'SGD']);
        $this->assertSame('sgd', Setting::get('currency'));
    }
}
