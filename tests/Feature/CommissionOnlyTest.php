<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashflowReport;
use App\Services\StripeService;
use App\Support\InvoiceDocument;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Invoice as StripeInvoice;
use Tests\TestCase;

/** The client pays the ad platform themselves; we bill only our commission on that spend. */
class CommissionOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_is_a_share_of_the_clients_own_spend(): void
    {
        $pricing = new Pricing(2.9, 30, 0.3, 20);
        $b = $pricing->commissionOnly(100000);

        $this->assertSame(20000, $b['net'], '20% of 1,000.00');
        $this->assertSame(20000, $b['commission'], 'all of it is commission');
        $this->assertSame(0, $b['ad_budget']);
        $this->assertSame(100000, $b['client_spend']);
        $this->assertSame($pricing->breakdown(20000)['charge'], $b['charge'], 'card fees on the commission only');
        $this->assertGreaterThanOrEqual(20000, $pricing->netOf($b['charge']));

        $absorbed = $pricing->commissionOnly(100000, passFees: false);
        $this->assertSame(20000, $absorbed['charge'], 'we absorb the fees: client pays exactly 200.00');
        $this->assertSame(20000, $absorbed['commission'], 'commission stays the full 200.00');
        $this->assertLessThan(20000, $absorbed['net'], 'the card fee is our cost');
    }

    public function test_gst_is_charged_on_the_commission_not_the_spend(): void
    {
        $b = (new Pricing(2.9, 30, 0.3, 20, 9))->commissionOnly(100000, passFees: false);

        $this->assertSame(1800, $b['tax'], '9% of 200.00');
        $this->assertSame(21800, $b['charge']);
    }

    public function test_invoice_line_says_what_the_commission_is_on(): void
    {
        $b = (new Pricing(2.9, 30, 0.3, 20))->commissionOnly(100000);
        $items = StripeService::budgetItems($b, 'Meta Ads budget');

        $this->assertSame('Management commission — 20% of 1,000.00 Meta Ads spend (paid directly by you)', $items[0]['description']);
        $this->assertSame(20000, $items[0]['amount']);
        $this->assertSame('Card processing fee', $items[1]['description']);
        $this->assertSame('100000', StripeService::budgetMetadata($b)['client_spend']);
        $this->assertArrayNotHasKey('client_spend', StripeService::budgetMetadata((new Pricing(2.9, 30, 0.3, 20))->breakdown(50000)));
    }

    public function test_commission_only_invoice_request(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg']);

        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('createInvoice')->once()
            ->withArgs(fn ($c, $items, $opts) => $items === [] && $opts['budget']['client_spend'] === 100000
                && $opts['budget']['charge'] > 20000 && $opts['budget']['processing_fee'] > 0 // client pays the card fee on top
                && $opts['budget']['net'] === 20000 && $opts['budget']['commission'] === 20000 && $opts['budget']['ad_budget'] === 0)
            ->andReturn(new Invoice));

        $this->actingAs(User::factory()->create())->post('/invoices', [
            'client_id' => $client->id, 'service' => 'meta_ads', 'days_until_due' => 7, 'send' => false,
            'billing' => 'commission', 'pass_fees' => false, 'commission_rate' => 20, 'budget_amount' => '1000', // pass_fees is ignored here
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect('/invoices');
    }

    public function test_synced_invoice_keeps_the_spend_and_reports_count_only_the_commission(): void
    {
        $client = Client::create(['name' => 'Bo', 'email' => 'bo@example.sg', 'stripe_test_customer_id' => 'cus_1']);
        $invoice = app(StripeService::class)->upsertInvoice(StripeInvoice::constructFrom([
            'id' => 'in_c1', 'customer' => 'cus_1', 'currency' => 'sgd', 'total' => 20000, 'amount_paid' => 20000, 'amount_remaining' => 0,
            'status' => 'paid', 'collection_method' => 'send_invoice', 'created' => now()->timestamp, 'due_date' => null, 'parent' => null,
            'description' => null, 'lines' => ['object' => 'list', 'data' => []], 'hosted_invoice_url' => null, 'invoice_pdf' => null, 'number' => 'KA-9',
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp],
            'metadata' => ['klok_client_id' => (string) $client->id, 'net_amount' => '20000', 'processing_fee' => '0', 'commission_rate' => '20',
                'commission_amount' => '20000', 'ad_budget' => '0', 'client_spend' => '100000'],
        ]));

        $this->assertSame(100000, $invoice->client_spend);

        $summary = CashflowReport::forPeriod('this_month')->summary();
        $this->assertSame(20000, $summary['commission']);
        $this->assertSame(0, $summary['ad_budget_received']);
        $this->assertSame(100000, $summary['client_spend']);

        // Every template shows the spend for reference.
        foreach (['classic_meta', 'pro_meta', 'classic_google', 'pro_google'] as $template) {
            $html = InvoiceDocument::for($invoice->fresh(), $template, 'receipt')->html();
            $this->assertStringContainsString('Paid directly by you to the ad platform', $html, $template);
            $this->assertStringContainsString('1,000.00', $html, $template);
        }
    }
}
