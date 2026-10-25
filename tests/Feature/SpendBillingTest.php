<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use App\Services\MetaAds;
use App\Services\SpendBilling;
use App\Services\StripeService;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SpendBillingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    /** @var array<int, array<string, mixed>> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-20 10:00');
        Setting::put('meta_token', str_repeat('x', 50));
        Setting::put('commission_rate', '20');
        $this->client = Client::create([
            'name' => 'Hor Wei Kang', 'company' => 'Tasfia Engineering', 'email' => 'hor@example.sg',
            'meta_ad_account_id' => 'act_111', 'meta_campaign_filter' => 'Tasfia', 'spend_billing' => true,
            'spend_threshold' => 50000, 'spend_billed_until' => '2026-10-09',
        ]);

        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('sgd');
            $m->shouldReceive('currencyFor')->andReturn('sgd');
            $m->shouldReceive('chargeNow')->andReturnUsing(function ($client, $amount, $description, $service, $pm, $budget) {
                $this->charges[] = compact('amount', 'description', 'budget');

                return Invoice::create(['stripe_invoice_id' => 'in_'.count($this->charges), 'client_id' => $client->id, 'number' => 'KA-00'.count($this->charges),
                    'currency' => 'sgd', 'status' => 'paid', 'total' => $amount, 'amount_paid' => $amount]);
            });
        });
    }

    private function metaReturns(array $rows): void
    {
        $this->mock(MetaAds::class, function (MockInterface $m) use ($rows) {
            $m->shouldReceive('account')->andReturn(['name' => 'Tasfia', 'currency' => 'SGD']);
            $m->shouldReceive('dailyCampaignSpend')->withArgs(fn ($acc, $from, $to, $filter) => $acc === 'act_111' && $filter === 'Tasfia')->andReturn($rows);
        });
    }

    private function day(string $date, int $spend, string $campaign = 'Tasfia Leads'): array
    {
        return ['campaign_id' => md5($campaign), 'campaign_name' => $campaign, 'date' => $date, 'spend' => $spend, 'impressions' => 1000, 'clicks' => 20];
    }

    public function test_charge_is_spend_plus_commission_plus_card_fee(): void
    {
        $b = SpendBilling::spendBudget(50000, new Pricing(2.9, 30, 0.3, 20));

        $this->assertSame([50000, 10000, 60000], [$b['spend'], $b['commission'], $b['net']]);
        $this->assertGreaterThan(60000, $b['charge']);
        $this->assertSame(['Meta Ads spend 10 – 14 Oct', 'Management commission — 20% of ad spend', 'Card processing fee'],
            array_column(StripeService::budgetItems($b, 'Meta Ads spend 10 – 14 Oct'), 'description'));
        $this->assertSame($b['charge'], array_sum(array_column(StripeService::budgetItems($b, 'x'), 'amount')));
    }

    public function test_charges_once_finished_days_reach_the_threshold(): void
    {
        $this->metaReturns([
            $this->day('2026-10-10', 20000), $this->day('2026-10-14', 15000, 'Tasfia Sales'),
            $this->day('2026-10-19', 16000), $this->day('2026-10-20', 9000), // today: not billed yet
        ]);
        $billing = app(SpendBilling::class);

        $billing->sync($this->client);
        $invoice = $billing->chargeIfDue($this->client->fresh());

        $this->assertNotNull($invoice);
        $this->assertCount(1, $this->charges);
        $this->assertSame(51000, $this->charges[0]['budget']['spend']);
        $this->assertSame(10200, $this->charges[0]['budget']['commission']);
        $this->assertSame('Meta Ads spend 10 Oct – 19 Oct', $this->charges[0]['description']);

        $fresh = $this->client->fresh();
        $this->assertSame('2026-10-19', $fresh->spend_billed_until->toDateString());
        $this->assertSame(3, MetaSpendDay::whereNotNull('invoice_id')->count());
        $this->assertNull(MetaSpendDay::firstWhere('date', '2026-10-20')->invoice_id);
        $this->assertSame(['Tasfia Leads', 'Tasfia Sales'], collect($invoice->details['campaigns'])->pluck('name')->sort()->values()->all());
        $this->assertDatabaseHas('expenses', ['client_id' => $this->client->id, 'category' => 'meta_ads', 'amount' => 51000]);

        // Nothing billed twice.
        $billing->sync($fresh);
        $this->assertNull($billing->chargeIfDue($fresh->fresh()));
        $this->assertCount(1, $this->charges);
    }

    public function test_below_threshold_waits_and_manual_charge_still_works(): void
    {
        $this->metaReturns([$this->day('2026-10-15', 30000)]);
        $billing = app(SpendBilling::class);
        $billing->sync($this->client);

        $this->assertNull($billing->chargeIfDue($this->client->fresh()));
        $this->assertSame([], $this->charges);

        $this->actingAs(User::factory()->create())->post("/clients/{$this->client->id}/meta/charge", ['confirm' => '1'])->assertSessionHas('success');
        $this->assertSame(30000, $this->charges[0]['budget']['spend']);
    }

    public function test_huge_unbilled_spend_waits_for_a_human(): void
    {
        $this->metaReturns([$this->day('2026-10-12', 300000)]);
        $billing = app(SpendBilling::class);
        $billing->sync($this->client);

        $this->assertNull($billing->chargeIfDue($this->client->fresh()));
        $this->assertStringContainsString('far above the threshold', $this->client->fresh()->spend_error);
    }

    public function test_a_declined_card_is_retried_after_a_day_not_every_hour(): void
    {
        $this->metaReturns([$this->day('2026-10-15', 60000)]);
        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('sgd');
            $m->shouldReceive('currencyFor')->andReturn('sgd');
            $m->shouldReceive('chargeNow')->once()->andThrow(new RuntimeException('Your card was declined.'));
        });
        $billing = app(SpendBilling::class);
        $billing->sync($this->client);

        $this->assertNull($billing->chargeIfDue($this->client->fresh()));
        $this->assertStringContainsString('declined', $this->client->fresh()->spend_error);
        $this->assertNull($billing->chargeIfDue($this->client->fresh()), 'no second attempt within 24h');
        $this->assertSame(0, Expense::count());
    }

    public function test_turning_auto_charge_on_counts_from_today_only(): void
    {
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg']);

        $this->actingAs(User::factory()->create())->post("/clients/{$client->id}/meta", [
            'meta_ad_account_id' => '1170923540578070', 'meta_campaign_filter' => 'Tasfia', 'spend_billing' => true, 'spend_threshold' => 500,
        ])->assertSessionHasNoErrors();

        $client->refresh();
        $this->assertSame('act_1170923540578070', $client->meta_ad_account_id);
        $this->assertSame(50000, $client->spend_threshold);
        $this->assertSame('2026-10-19', $client->spend_billed_until->toDateString());
    }
}
