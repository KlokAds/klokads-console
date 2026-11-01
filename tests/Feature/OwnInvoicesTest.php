<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeClient;
use Tests\TestCase;

/** Invoices are ours (number, totals, pay page); Stripe only takes the card payment — no Stripe Invoicing fee. */
class OwnInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    /** @var array<string, MockInterface> */
    private array $api = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg', 'stripe_test_customer_id' => 'cus_1', 'currency' => 'sgd']);

        $fake = new class('sk_test_abc123') extends StripeClient
        {
            public $paymentIntents;

            public $paymentMethods;

            public $charges;

            public $invoices;
        };
        foreach (['paymentIntents', 'paymentMethods', 'charges', 'invoices'] as $service) {
            $fake->{$service} = $this->api[$service] = Mockery::mock();
        }
        // Stripe Invoicing is never used for a new invoice.
        $this->api['invoices']->shouldNotReceive('create');
        $this->api['paymentMethods']->shouldReceive('retrieve')->andReturn(PaymentMethod::constructFrom(['id' => 'pm_1', 'customer' => 'cus_1']));

        $this->app->instance(StripeService::class, new class($fake) extends StripeService
        {
            public function __construct(private StripeClient $fake) {}

            public function stripe(): StripeClient
            {
                return $this->fake;
            }
        });
    }

    private function succeeded(int $amount): PaymentIntent
    {
        return PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'succeeded', 'amount' => $amount, 'client_secret' => 'pi_1_secret',
            'latest_charge' => Charge::constructFrom(['id' => 'ch_1', 'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]])]);
    }

    public function test_a_new_invoice_is_ours_with_our_number_and_gst(): void
    {
        Setting::put('gst_enabled', '1');
        Setting::put('gst_rate', '9');

        $first = app(StripeService::class)->createInvoice($this->client, [['description' => 'Website', 'amount' => 10000, 'quantity' => 2]], ['service' => 'web']);
        $second = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);

        $this->assertNull($first->stripe_invoice_id);
        $this->assertSame(['KA-0001', 'open', 20000, 1800, 21800, 21800], [$first->number, $first->status, $first->subtotal, $first->tax_amount, $first->total, $first->amount_remaining]);
        $this->assertSame('Website × 2', $first->lines[0]['description']);
        $this->assertSame('KA-0002', $second->number);
    }

    public function test_charging_a_saved_card_makes_a_paid_invoice_only_when_the_card_went_through(): void
    {
        $this->api['paymentIntents']->shouldReceive('create')->once()
            ->withArgs(fn ($p, ...$options) => $options === [] && $p['amount'] === 15000 && $p['off_session'] && $p['payment_method'] === 'pm_1' && $p['statement_descriptor_suffix'] === 'META ADS '.strtoupper(now()->format('M')))
            ->andReturn($this->succeeded(15000));
        $this->api['paymentIntents']->shouldReceive('update')->once();

        $invoice = app(StripeService::class)->chargeNow($this->client, 15000, 'Meta Ads spend', 'meta_ads', 'pm_1');

        $this->assertSame(['KA-0001', 'paid', 15000, 0, 'Visa ···· 4242', 'pi_1'], [$invoice->number, $invoice->status, $invoice->amount_paid, $invoice->amount_remaining, $invoice->paid_with, $invoice->stripe_payment_intent_id]);
    }

    public function test_one_tap_charge_from_the_invoice_list_pays_it(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);
        $this->api['paymentIntents']->shouldReceive('create')->once()->withArgs(fn ($p, ...$options) => $options === [] && $p['amount'] === 5000 && $p['off_session'])->andReturn($this->succeeded(5000));

        $this->from('/invoices')->post("/invoices/{$invoice->id}/charge", ['payment_method' => 'pm_1'])->assertSessionHas('success');

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_the_client_pays_with_their_saved_card_on_the_invoice_page(): void
    {
        $invoice = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);
        $this->api['paymentIntents']->shouldReceive('create')->once()->withArgs(fn ($p, ...$options) => $options === [] && ! isset($p['off_session']) && isset($p['return_url']))->andReturn($this->succeeded(5000));

        $this->postJson(route('public.invoice.pay-saved', $invoice->publicToken()), ['payment_method' => 'pm_1'])->assertOk()->assertJson(['paid' => true]);
        $this->assertSame('paid', $invoice->fresh()->status);

        // Paid: the page can't charge it again.
        $this->postJson(route('public.invoice.pay-saved', $invoice->publicToken()), ['payment_method' => 'pm_1'])->assertStatus(422);
    }

    public function test_an_open_invoice_can_be_changed_before_it_is_paid(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);
        $invoice->update(['stripe_payment_intent_id' => 'pi_old']);
        $this->api['paymentIntents']->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_old', 'status' => 'requires_payment_method', 'amount' => 5000]));
        $this->api['paymentIntents']->shouldReceive('cancel')->once()->with('pi_old');

        $this->get("/invoices/{$invoice->id}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Invoices/Create')
            ->where('invoice.number', 'KA-0001')->where('invoice.billing', 'standard')->where('invoice.items.0.amount', 50));

        $this->put("/invoices/{$invoice->id}", [
            'client_id' => $this->client->id, 'days_until_due' => 10, 'billing' => 'standard', 'send' => false,
            'items' => [['description' => 'SEO', 'amount' => 50, 'quantity' => 1], ['description' => 'Report', 'amount' => 20, 'quantity' => 1]],
        ])->assertRedirect('/invoices')->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(['KA-0001', 7000, 7000, null], [$invoice->number, $invoice->total, $invoice->amount_remaining, $invoice->stripe_payment_intent_id]);
        $this->assertCount(2, $invoice->lines);
    }

    public function test_a_paid_invoice_cannot_be_changed(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);
        app(StripeService::class)->markInvoicePaid($invoice);

        $this->get("/invoices/{$invoice->id}/edit")->assertRedirect('/invoices');
    }

    public function test_marking_our_invoice_paid_needs_no_stripe_invoice(): void
    {
        $invoice = app(StripeService::class)->createInvoice($this->client, [['description' => 'SEO', 'amount' => 5000, 'quantity' => 1]], []);

        app(StripeService::class)->markInvoicePaid($invoice);

        $this->assertSame(['paid', 5000, 'bank transfer'], [$invoice->fresh()->status, $invoice->fresh()->amount_paid, $invoice->fresh()->paid_with]);
    }
}
