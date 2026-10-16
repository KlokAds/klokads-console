<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashflowReport;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Invoice as StripeInvoice;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_invoice_request_is_priced_so_we_net_the_amount(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg', 'commission_rate' => 15]);

        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('createInvoice')->once()
            ->withArgs(function ($c, $items, $opts) {
                $b = $opts['budget'];

                return $b['net'] === 50000 && $b['charge'] === 51679 && $b['commission'] === 7500
                    && $b['ad_budget'] === 42500 && $opts['budget_label'] === 'Meta Ads budget';
            })->andReturn(new Invoice));

        $this->actingAs(User::factory()->create())->post('/invoices', [
            'client_id' => $client->id, 'service' => 'meta_ads', 'days_until_due' => 7, 'send' => false,
            'billing' => 'budget', 'pass_fees' => true, 'commission_rate' => 15, 'budget_amount' => '500',
        ])->assertRedirect('/invoices');
    }

    public function test_budget_lines_and_metadata(): void
    {
        $b = ['net' => 50000, 'processing_fee' => 1679, 'commission_rate' => 20.0, 'commission' => 10000, 'ad_budget' => 40000, 'charge' => 51679];

        $this->assertSame([
            ['description' => 'Meta Ads budget', 'amount' => 50000, 'quantity' => 1],
            ['description' => 'Card processing fee', 'amount' => 1679, 'quantity' => 1],
        ], StripeService::budgetItems($b, 'Meta Ads budget'));

        $this->assertSame('10000', StripeService::budgetMetadata($b)['commission_amount']);
    }

    public function test_synced_invoices_keep_commission_from_metadata_or_their_plan(): void
    {
        $client = Client::create(['name' => 'Bo', 'email' => 'bo@example.sg', 'stripe_test_customer_id' => 'cus_1']);
        $svc = app(StripeService::class);
        $base = [
            'customer' => 'cus_1', 'currency' => 'usd', 'total' => 51679, 'amount_paid' => 51679, 'amount_remaining' => 0,
            'status' => 'paid', 'collection_method' => 'charge_automatically', 'created' => now()->timestamp, 'due_date' => null,
            'description' => 'x', 'lines' => ['object' => 'list', 'data' => []], 'hosted_invoice_url' => null, 'invoice_pdf' => null,
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp], 'number' => 'A-1',
        ];

        $one = $svc->upsertInvoice(StripeInvoice::constructFrom($base + ['id' => 'in_1', 'parent' => null,
            'metadata' => ['klok_client_id' => (string) $client->id, 'net_amount' => '50000', 'processing_fee' => '1679', 'commission_rate' => '20', 'commission_amount' => '10000', 'ad_budget' => '40000']]));
        $this->assertSame(10000, $one->commission_amount);

        RecurringPlan::create(['client_id' => $client->id, 'stripe_subscription_id' => 'sub_1', 'description' => 'Monthly', 'amount' => 51679, 'currency' => 'usd',
            'interval' => 'month', 'collection' => 'auto', 'status' => 'active', 'net_amount' => 50000, 'processing_fee' => 1679, 'commission_rate' => 20, 'commission_amount' => 10000, 'ad_budget' => 40000]);
        $two = $svc->upsertInvoice(StripeInvoice::constructFrom($base + ['id' => 'in_2', 'metadata' => [],
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1', 'metadata' => []]]]));
        $this->assertSame(10000, $two->commission_amount);

        $this->assertSame(20000, CashflowReport::forPeriod('this_month')->summary()['commission']);

        Expense::create(['category' => 'meta_ads', 'client_id' => $client->id, 'amount' => 30000, 'spent_on' => now()->toDateString()]);
        $this->assertSame(['received' => 80000, 'spent' => 30000, 'left' => 50000], CashflowReport::budgetBalances()[$client->id]);
    }

    public function test_pricing_settings_are_saved(): void
    {
        $this->actingAs(User::factory()->create())->post('/settings/pricing', [
            'commission_rate' => 18, 'card_fee_percent' => 3.4, 'card_fee_fixed' => '0.50', 'payout_fee_percent' => 0.3,
        ])->assertSessionHasNoErrors();

        $this->assertSame('18', Setting::get('commission_rate'));
        $this->assertSame('50', Setting::get('card_fee_fixed'));
    }
}
