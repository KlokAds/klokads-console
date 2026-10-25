<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashflowReport;
use App\Services\PrepaidBalance;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PrepaidBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    /** @var array<int, array<string, mixed>> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-20 10:00');
        Setting::put('commission_rate', '20');
        $this->client = Client::create([
            'name' => 'Hor', 'company' => 'Tasfia Engineering', 'email' => 'hor@example.sg', 'meta_ad_account_id' => 'act_111',
            'spend_billing' => true, 'approve_charges' => false, 'billing_mode' => 'prepaid', 'prepaid_buffer_days' => 10, 'prepaid_topup_days' => 21,
            'prepaid_min_topup' => 50000, 'spend_billed_until' => '2026-10-12',
        ]);

        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('sgd');
            $m->shouldReceive('currencyFor')->andReturn('sgd');
            $m->shouldReceive('chargeNow')->andReturnUsing(function ($client, $amount, $description, $service, $pm, $budget) {
                $this->charges[] = compact('amount', 'description', 'budget');

                return Invoice::create(['stripe_invoice_id' => 'in_'.count($this->charges), 'client_id' => $client->id, 'number' => 'KA-T'.count($this->charges),
                    'currency' => 'sgd', 'status' => 'paid', 'total' => $amount, 'amount_paid' => $amount]);
            });
        });
    }

    /** S$50 a day on each of the last 7 finished days, plus today so far. */
    private function spendEveryDay(int $cents = 5000): void
    {
        foreach (range(1, 7) as $ago) {
            MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads',
                'date' => CarbonImmutable::today()->subDays($ago)->toDateString(), 'spend' => $cents, 'currency' => 'SGD']);
        }
        MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Tasfia Leads',
            'date' => CarbonImmutable::today()->toDateString(), 'spend' => 2000, 'currency' => 'SGD']);
    }

    public function test_finished_days_come_out_of_the_balance_with_commission(): void
    {
        $this->spendEveryDay();
        app(PrepaidBalance::class)->adjust($this->client, 100000, 'Bank transfer');

        $this->assertSame(7, app(PrepaidBalance::class)->deduct($this->client), 'days after the 12th, before today');
        $this->assertSame(100000 - 7 * 6000, $this->client->balance());
        $this->assertSame('2026-10-19', $this->client->fresh()->spend_billed_until->toDateString());
        $this->assertSame(0, app(PrepaidBalance::class)->deduct($this->client->fresh()), 'never twice');
        $this->assertDatabaseHas('expenses', ['client_id' => $this->client->id, 'category' => 'meta_ads', 'amount' => 35000, 'reference' => "meta-auto:{$this->client->id}:2026-10"]);
    }

    public function test_tops_up_before_the_backup_runs_out(): void
    {
        $this->spendEveryDay(); // S$60/day incl. commission → backup S$600, top-up S$1,260
        app(PrepaidBalance::class)->adjust($this->client, 100000, 'Opening balance');

        $invoice = app(PrepaidBalance::class)->run($this->client);

        // 1,000 − 7×60 = 580 left (< 600 backup) → top up 21 days (1,260) − 580 = 680
        $this->assertNotNull($invoice);
        $this->assertSame(68000, $this->charges[0]['budget']['ad_budget']);
        $this->assertSame(0, $this->charges[0]['budget']['commission'], 'commission is earned when spent, not on top-up');
        $this->assertGreaterThan(68000, $this->charges[0]['amount'], 'card fee on top');
        $this->assertSame(58000 + 68000, $this->client->balance());
        $this->assertFalse(app(PrepaidBalance::class)->status($this->client->fresh())['low']);

        // Healthy balance: no second top-up.
        $this->assertNull(app(PrepaidBalance::class)->run($this->client->fresh()));
        $this->assertCount(1, $this->charges);
    }

    public function test_minimum_top_up_and_safety_cap(): void
    {
        MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'x', 'date' => '2026-10-19', 'spend' => 700, 'currency' => 'SGD']);
        app(PrepaidBalance::class)->run($this->client);
        $this->assertSame(50000, $this->charges[0]['budget']['ad_budget'], 'small spend still tops up at least 500');

        $big = Client::create(['name' => 'Big', 'email' => 'big@example.sg', 'meta_ad_account_id' => 'act_2', 'spend_billing' => true,
            'billing_mode' => 'prepaid', 'prepaid_min_topup' => 50000, 'spend_billed_until' => '2026-10-12']);
        foreach (range(1, 7) as $ago) {
            MetaSpendDay::create(['client_id' => $big->id, 'campaign_id' => 'c', 'campaign_name' => 'x', 'date' => CarbonImmutable::today()->subDays($ago)->toDateString(), 'spend' => 100000, 'currency' => 'SGD']);
        }
        $this->assertNull(app(PrepaidBalance::class)->run($big));
        $this->assertStringContainsString('unusually large', $big->fresh()->spend_error);
        $this->assertCount(1, $this->charges);
    }

    public function test_commission_counts_in_reports_when_spent(): void
    {
        $this->spendEveryDay();
        app(PrepaidBalance::class)->adjust($this->client, 200000, 'Bank transfer');
        app(PrepaidBalance::class)->deduct($this->client);

        $this->assertSame(7 * 1000, CashflowReport::forPeriod('this_month')->summary()['commission']);
    }

    public function test_client_page_shows_the_balance_and_settings_can_switch_mode(): void
    {
        $this->spendEveryDay();
        app(PrepaidBalance::class)->adjust($this->client, 100000, 'Bank transfer');
        $user = User::factory()->create();

        $this->actingAs($user)->get("/clients/{$this->client->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('meta.prepaid.balance', 100000)
            ->where('meta.prepaid.daily', 6000)
            ->where('meta.prepaid.entries.0.note', 'Bank transfer'));

        $this->post("/clients/{$this->client->id}/meta", [
            'meta_ad_account_id' => 'act_111', 'spend_billing' => true, 'spend_threshold' => 500, 'billing_mode' => 'postpaid',
            'prepaid_buffer_days' => 10, 'prepaid_topup_days' => 5, 'prepaid_min_topup' => 500,
        ])->assertSessionHasErrors('prepaid_topup_days');

        $this->post("/clients/{$this->client->id}/meta/adjust", ['amount' => -50, 'note' => 'Correction'])->assertSessionHasNoErrors();
        $this->assertSame(95000, $this->client->balance());
        $this->assertSame(0, Expense::where('category', 'meta_ads')->count());
        $this->assertSame(2, ClientBalanceEntry::count());
    }
}
