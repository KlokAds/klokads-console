<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringPlan;
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

    public function test_card_link_is_our_own_signed_page(): void
    {
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('ensureCustomer')->once()->andReturn('cus_1'));

        $this->from("/clients/{$this->client->id}")->post("/clients/{$this->client->id}/cards/link")->assertSessionHas('success');

        $link = session('card_link');
        $this->assertStringContainsString("/card/{$this->client->id}?", $link);
        $this->assertStringContainsString('signature=', $link);
    }

    public function test_client_add_card_page_needs_a_valid_signature(): void
    {
        auth()->logout();
        Setting::put('stripe_publishable', 'pk_test_abc');
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('cardSetupSecret')->once()->andReturn('seti_1_secret_x'));

        $this->get("/card/{$this->client->id}")->assertForbidden();

        $this->get(StripeService::cardLink($this->client))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/AddCard')
                ->where('setup.client_secret', 'seti_1_secret_x')
                ->where('setup.publishable_key', 'pk_test_abc')
                ->where('client.name', 'Ann'));
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

    public function test_enter_card_now_returns_a_setup_secret_for_the_popup(): void
    {
        $this->postJson("/clients/{$this->client->id}/cards/enter")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'publishable key'));

        Setting::put('stripe_publishable', 'pk_test_abc');
        $this->mock(StripeService::class, fn (MockInterface $m) => $m->shouldReceive('cardSetupSecret')->once()
            ->withArgs(fn ($c) => $c->is($this->client))->andReturn('seti_2_secret_y'));

        $this->postJson("/clients/{$this->client->id}/cards/enter")->assertOk()
            ->assertExactJson(['client_secret' => 'seti_2_secret_y', 'publishable_key' => 'pk_test_abc']);
    }

    public function test_saved_methods_other_than_cards_are_described(): void
    {
        $bank = (object) ['id' => 'pm_b', 'type' => 'us_bank_account', 'us_bank_account' => (object) ['last4' => '6789', 'bank_name' => 'STRIPE TEST BANK']];
        $link = (object) ['id' => 'pm_l', 'type' => 'link', 'link' => (object) ['email' => 'ann@example.sg']];

        $this->assertSame(['id' => 'pm_b', 'type' => 'us_bank_account', 'brand' => 'us_bank_account', 'last4' => '6789', 'exp_month' => null, 'exp_year' => null, 'detail' => 'STRIPE TEST BANK'],
            StripeService::describePaymentMethod($bank));
        $this->assertSame('ann@example.sg', StripeService::describePaymentMethod($link)['detail']);
    }

    public function test_default_card_is_locked_only_while_a_recurring_plan_is_running(): void
    {
        $this->assertFalse(StripeService::defaultCardLocked($this->client));

        $plan = RecurringPlan::create(['client_id' => $this->client->id, 'stripe_subscription_id' => 'sub_1', 'description' => 'Monthly', 'amount' => 1000,
            'currency' => 'sgd', 'interval' => 'month', 'collection' => 'auto', 'status' => 'active']);
        $this->assertTrue(StripeService::defaultCardLocked($this->client));

        $plan->update(['status' => 'canceled']);
        $this->assertFalse(StripeService::defaultCardLocked($this->client));
    }

    public function test_the_card_saved_page_is_public(): void
    {
        auth()->logout();

        $this->get('/card-saved')->assertOk();
    }
}
