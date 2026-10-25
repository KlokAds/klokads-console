<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ChargeRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use App\Services\PrepaidBalance;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/** An automatic top-up waits for my approval; I can change it; only then the card is charged. */
class ChargeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        CarbonImmutable::setTestNow('2026-10-25 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
        Setting::put('commission_rate', '20');
        $this->client = Client::create([
            'name' => 'Hor', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com', 'meta_ad_account_id' => 'act_808',
            'spend_billing' => true, 'billing_mode' => 'prepaid', 'currency' => 'sgd', 'prepaid_buffer_days' => 7, 'prepaid_topup_days' => 14,
            'prepaid_min_topup' => 50000, 'spend_billed_until' => '2026-10-24',
        ]);
        foreach (range(1, 7) as $ago) {
            MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads',
                'date' => CarbonImmutable::today()->subDays($ago)->toDateString(), 'spend' => 5000, 'currency' => 'SGD']);
        }
        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('usd');
            $m->shouldReceive('currencyFor')->andReturn('sgd');
            $m->shouldReceive('chargeNow')->andReturnUsing(function ($client, $amount, $description) {
                $this->charges[] = compact('amount', 'description');

                return Invoice::create(['stripe_invoice_id' => 'in_'.count($this->charges), 'client_id' => $client->id, 'number' => 'KA-T'.count($this->charges),
                    'currency' => 'sgd', 'status' => 'paid', 'total' => $amount, 'amount_paid' => $amount]);
            });
        });
    }

    public function test_a_low_balance_drafts_a_top_up_and_asks_instead_of_charging(): void
    {
        $prepaid = app(PrepaidBalance::class);
        $this->assertNull($prepaid->run($this->client));
        $this->assertNull($prepaid->run($this->client->fresh()), 'the next hour: still the same one');

        $this->assertSame([], $this->charges, 'nothing charged without approval');
        $draft = ChargeRequest::sole();
        $this->assertSame('pending', $draft->status);
        $this->assertSame(84000, $draft->amount, '14 days × S$60 (spend + 20%)');
        $this->assertSame('SGD', $draft->currency);
        $this->assertSame(1, AppNotification::where('title', 'like', '🧾 Approve top-up: Tasfia Engineering%')->count());
        $this->assertStringContainsString('charges/'.$draft->id, AppNotification::where('title', 'like', '🧾 Approve%')->value('url'));

        $this->get("/clients/{$this->client->id}")->assertInertia(fn ($page) => $page->where('meta.settings.waiting.id', $draft->id));
    }

    public function test_i_change_the_amount_and_approve_then_the_card_is_charged(): void
    {
        app(PrepaidBalance::class)->run($this->client);
        $draft = ChargeRequest::sole();

        $this->get("/charges/{$draft->id}")->assertInertia(fn ($page) => $page->component('Charges/Show')->where('charge.suggested', 84000));
        $this->post("/charges/{$draft->id}/approve", ['amount' => '1000.00', 'description' => 'Meta ads top-up — Nov campaign'])->assertSessionHas('success');

        $this->assertCount(1, $this->charges);
        $this->assertSame('Meta ads top-up — Nov campaign', $this->charges[0]['description']);
        $this->assertGreaterThan(100000, $this->charges[0]['amount'], 'S$1,000 + card fee');
        $draft->refresh();
        $this->assertSame('charged', $draft->status);
        $this->assertSame(100000, $draft->amount);
        $this->assertSame(100000, $this->client->fresh()->balance(), 'S$1,000 into the balance');

        $this->post("/charges/{$draft->id}/approve", ['amount' => '1000.00', 'description' => 'again'])->assertStatus(409);
        $this->assertCount(1, $this->charges, 'never charged twice');
    }

    public function test_declined_means_nothing_charged_and_no_new_draft_until_tomorrow(): void
    {
        app(PrepaidBalance::class)->run($this->client);
        $draft = ChargeRequest::sole();

        $this->post("/charges/{$draft->id}/decline")->assertSessionHas('success');
        app(PrepaidBalance::class)->run($this->client->fresh());

        $this->assertSame([], $this->charges);
        $this->assertSame(1, ChargeRequest::count());
        $this->assertSame('declined', $draft->fresh()->status);
    }
}
