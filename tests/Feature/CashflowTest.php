<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowReport;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\BalanceTransaction;
use Tests\TestCase;

class CashflowTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $category, int $amount, int $fee = 0, array $extra = []): Transaction
    {
        static $n = 0;

        return Transaction::create([
            'stripe_id' => 'txn_'.(++$n),
            'type' => $category,
            'reporting_category' => $category,
            'amount' => $amount,
            'fee' => $fee,
            'net' => $amount - $fee,
            'currency' => 'sgd',
            'occurred_at' => now(),
            ...$extra,
        ]);
    }

    public function test_summary_separates_gross_fees_net_payouts_and_profit(): void
    {
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg']);

        $this->tx('charge', 100000, 3400, ['client_id' => $client->id, 'payment_method' => 'card:visa']);
        $this->tx('charge', 50000, 1750, ['payment_method' => 'paynow']);
        $this->tx('refund', -10000);
        $this->tx('fee', -500);                 // Stripe invoicing fee row
        $this->tx('payout', -120000);
        Expense::create(['category' => 'meta_ads', 'amount' => 40000, 'spent_on' => now()->toDateString(), 'client_id' => $client->id]);
        Expense::create(['category' => 'software', 'amount' => 5000, 'spent_on' => now()->toDateString()]);

        $report = CashflowReport::forPeriod('this_month');
        $s = $report->summary();

        $this->assertSame(150000, $s['gross']);
        $this->assertSame(10000, $s['refunds']);
        $this->assertSame(3400 + 1750 + 500, $s['fees']);
        $this->assertSame(150000 - 5150 - 10000 - 500, $s['net']);
        $this->assertSame(120000, $s['payouts']);
        $this->assertSame(45000, $s['expenses']);
        $this->assertSame($s['net'] - 45000, $s['profit']);

        $clients = $report->clients();
        $this->assertSame(100000, $clients[0]['revenue']);
        $this->assertSame(96600 - 40000, $clients[0]['profit']);

        $methods = collect($report->paymentMethods())->keyBy('method');
        $this->assertSame(1, $methods['paynow']['count']);
        $this->assertSame(3400, $methods['card:visa']['fees']);
    }

    public function test_activity_outside_the_period_is_excluded(): void
    {
        $this->tx('charge', 9999, 0, ['occurred_at' => now()->subMonths(3)]);
        Expense::create(['category' => 'seo', 'amount' => 777, 'spent_on' => now()->subMonths(3)->toDateString()]);

        $s = CashflowReport::forPeriod('this_month')->summary();

        $this->assertSame(0, $s['gross']);
        $this->assertSame(0, $s['expenses']);
        $this->assertSame(9999, CashflowReport::forPeriod('all')->summary()['gross']);
    }

    public function test_previous_period_is_the_comparable_window_before(): void
    {
        $this->travelTo('2026-10-16 15:00:00');

        $thisMonth = CashflowReport::forPeriod('this_month')->previous('this_month');
        $this->assertSame('2026-09-01', $thisMonth->from->toDateString());
        $this->assertSame('2026-09-16', $thisMonth->to->toDateString());

        $lastMonth = CashflowReport::forPeriod('last_month')->previous('last_month');
        $this->assertSame('2026-08-01', $lastMonth->from->toDateString());
        $this->assertSame('2026-08-31', $lastMonth->to->toDateString());

        $custom = CashflowReport::forPeriod('custom', '2026-10-01', '2026-10-10')->previous('custom');
        $this->assertSame('2026-09-21', $custom->from->toDateString());
        $this->assertSame('2026-09-30', $custom->to->toDateString());

        $this->assertNull(CashflowReport::forPeriod('all')->previous('all'));
    }

    public function test_mrr_normalises_intervals_and_ignores_cancelled_plans(): void
    {
        $client = Client::create(['name' => 'Bo', 'email' => 'bo@example.sg']);
        $plan = fn (array $a) => RecurringPlan::create([
            'client_id' => $client->id, 'stripe_subscription_id' => 'sub_'.uniqid(), 'description' => 'x',
            'currency' => 'sgd', 'interval_count' => 1, 'collection' => 'auto', 'status' => 'active', ...$a,
        ]);

        $plan(['amount' => 200000, 'interval' => 'month']);
        $plan(['amount' => 1200000, 'interval' => 'year']);
        $plan(['amount' => 300000, 'interval' => 'month', 'interval_count' => 3]);
        $plan(['amount' => 999999, 'interval' => 'month', 'status' => 'canceled']);

        $this->assertSame(200000 + 100000 + 100000, CashflowReport::receivables()['mrr']);
    }

    public function test_stripe_invoice_is_mapped_to_client_and_service(): void
    {
        $client = Client::create(['name' => 'Cy', 'email' => 'cy@example.sg', 'stripe_customer_id' => 'cus_123']);

        $invoice = app(StripeService::class)->upsertInvoice(\Stripe\Invoice::constructFrom([
            'id' => 'in_1', 'customer' => 'cus_123', 'number' => 'KA-0001', 'currency' => 'sgd',
            'total' => 150000, 'amount_paid' => 150000, 'amount_remaining' => 0, 'status' => 'paid',
            'collection_method' => 'send_invoice', 'created' => now()->timestamp, 'due_date' => null,
            'metadata' => ['service' => 'google_ads'], 'description' => null, 'parent' => null,
            'lines' => ['object' => 'list', 'data' => [['description' => 'Google Ads — October']]],
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp],
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/x', 'invoice_pdf' => null,
        ]));

        $this->assertSame($client->id, $invoice->client_id);
        $this->assertSame('google_ads', $invoice->service);
        $this->assertSame('Google Ads — October', $invoice->description);

        $services = collect(CashflowReport::forPeriod('this_month')->services())->keyBy('key');
        $this->assertSame(150000, $services['google_ads']['revenue']);
    }

    public function test_balance_transaction_records_fee_method_and_client(): void
    {
        $client = Client::create(['name' => 'Di', 'email' => 'di@example.sg', 'stripe_customer_id' => 'cus_9']);

        $tx = app(StripeService::class)->upsertTransaction(BalanceTransaction::constructFrom([
            'id' => 'txn_x', 'type' => 'charge', 'reporting_category' => 'charge', 'amount' => 50000, 'fee' => 1750,
            'net' => 48250, 'currency' => 'sgd', 'description' => null, 'status' => 'available',
            'available_on' => now()->timestamp, 'created' => now()->timestamp,
            'source' => ['id' => 'ch_1', 'object' => 'charge', 'customer' => 'cus_9', 'metadata' => [],
                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'mastercard']]],
        ]));

        $this->assertSame($client->id, $tx->client_id);
        $this->assertSame('card:mastercard', $tx->payment_method);
        $this->assertSame(1750, $tx->fee);
    }

    public function test_stripe_secret_is_encrypted_at_rest(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');

        $raw = DB::table('settings')->where('key', 'stripe_secret')->value('value');
        $this->assertStringNotContainsString('sk_test_abc123', $raw);
        $this->assertSame('sk_test_abc123', Setting::get('stripe_secret'));
    }

    public function test_webhook_rejects_unsigned_requests(): void
    {
        Setting::put('stripe_webhook_secret', 'whsec_test');

        $this->postJson('/stripe/webhook', ['type' => 'invoice.paid'])->assertStatus(400);
    }

    public function test_expense_amounts_are_stored_in_cents(): void
    {
        $this->actingAs(User::factory()->create())->post('/expenses', [
            'category' => 'tiktok_ads', 'amount' => '1234.56', 'spent_on' => '2026-10-01',
            'original_amount' => '900', 'original_currency' => 'USD',
        ])->assertSessionHasNoErrors();

        $expense = Expense::first();
        $this->assertSame(123456, $expense->amount);
        $this->assertSame(90000, $expense->original_amount);
        $this->assertSame('usd', $expense->original_currency);
    }

    public function test_pages_render_without_stripe_connected(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Ed', 'email' => 'ed@example.sg']);
        Invoice::create(['stripe_invoice_id' => 'in_z', 'client_id' => $client->id, 'currency' => 'sgd', 'status' => 'open', 'total' => 100, 'amount_remaining' => 100]);

        foreach (['/dashboard', '/clients', "/clients/{$client->id}", '/invoices', '/invoices/create', '/recurring', '/payment-links', '/expenses', '/cashflow', '/settings'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('connected', false)->where('receivables.outstanding', 100));
        $this->actingAs($user)->get('/cashflow/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_creating_an_invoice_without_stripe_shows_an_error_instead_of_crashing(): void
    {
        $client = Client::create(['name' => 'Fi', 'email' => 'fi@example.sg']);

        $this->actingAs(User::factory()->create())->from('/invoices/create')->post('/invoices', [
            'client_id' => $client->id, 'days_until_due' => 7, 'send' => false,
            'items' => [['description' => 'Meta Ads', 'amount' => 500, 'quantity' => 1]],
        ])->assertRedirect('/invoices/create')->assertSessionHas('error');
    }

    public function test_admin_command_creates_a_verified_login(): void
    {
        $this->artisan('klok:admin', ['email' => 'boss@example.sg', '--password' => 'secret-pass-123'])->assertSuccessful();

        $user = User::firstWhere('email', 'boss@example.sg');
        $this->assertNotNull($user->email_verified_at);
        $this->post('/login', ['email' => 'boss@example.sg', 'password' => 'secret-pass-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_is_closed_once_an_owner_exists(): void
    {
        User::factory()->create();

        $this->get('/register')->assertForbidden();
    }
}
