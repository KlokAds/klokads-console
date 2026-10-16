<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ClientCardTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg', 'stripe_test_customer_id' => 'cus_1']);
        $this->actingAs(User::factory()->create());
    }

    public function test_card_link_is_created_and_flashed(): void
    {
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('cardSetupLink')
            ->once()->withArgs(fn ($client, $url) => $client->is($this->client) && str_ends_with($url, '/card-saved'))
            ->andReturn('https://checkout.stripe.com/c/pay/cs_test_1'));

        $this->from("/clients/{$this->client->id}")->post("/clients/{$this->client->id}/cards/link")
            ->assertSessionHas('card_link', 'https://checkout.stripe.com/c/pay/cs_test_1');
    }

    public function test_default_card_can_be_changed(): void
    {
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('setDefaultPaymentMethod')->once()->withArgs(fn ($c, $pm) => $pm === 'pm_2'));

        $this->from("/clients/{$this->client->id}")->post("/clients/{$this->client->id}/cards/pm_2/default")->assertSessionHas('success');
    }

    public function test_someone_elses_card_is_rejected_with_a_message(): void
    {
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('removePaymentMethod')->andThrow(new RuntimeException('That card does not belong to this client.')));

        $this->from("/clients/{$this->client->id}")->delete("/clients/{$this->client->id}/cards/pm_x")
            ->assertSessionHas('error', 'Stripe: That card does not belong to this client.');
    }

    public function test_open_invoice_can_be_charged_to_the_default_card(): void
    {
        $invoice = Invoice::create(['stripe_invoice_id' => 'in_1', 'client_id' => $this->client->id, 'currency' => 'usd', 'status' => 'open', 'total' => 500, 'amount_remaining' => 500]);
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('chargeInvoice')->once()->withArgs(fn ($inv, $pm) => $inv->is($invoice) && $pm === null)->andReturn($invoice));

        $this->from("/clients/{$this->client->id}")->post("/invoices/{$invoice->id}/charge")->assertSessionHas('success');
    }

    public function test_charge_now_needs_confirmation_and_sends_cents(): void
    {
        $mock = $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('usd');
        });

        $this->from("/clients/{$this->client->id}")->post("/clients/{$this->client->id}/charge", ['amount' => '50', 'description' => 'Extra budget'])
            ->assertSessionHasErrors('confirm');

        $mock->shouldReceive('chargeNow')->once()
            ->withArgs(fn ($c, $amount, $desc, $service, $pm) => $c->is($this->client) && $amount === 12550 && $desc === 'Extra budget' && $service === 'meta_ads' && $pm === 'pm_2')
            ->andReturn(new Invoice);

        $this->from("/clients/{$this->client->id}")->post("/clients/{$this->client->id}/charge", [
            'amount' => '125.50', 'description' => 'Extra budget', 'service' => 'meta_ads', 'payment_method' => 'pm_2', 'confirm' => '1',
        ])->assertSessionHas('success');
    }

    public function test_enter_card_now_sends_the_admin_to_stripe(): void
    {
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('cardSetupLink')
            ->once()->withArgs(fn ($c, $url) => str_contains($url, "/clients/{$this->client->id}?card=added"))
            ->andReturn('https://checkout.stripe.com/c/pay/cs_test_2'));

        $this->post("/clients/{$this->client->id}/cards/enter", [], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_2');
    }

    public function test_the_card_saved_page_is_public(): void
    {
        auth()->logout();

        $this->get('/card-saved')->assertOk();
    }
}
