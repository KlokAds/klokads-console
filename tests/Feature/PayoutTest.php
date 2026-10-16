<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('stripe_secret', 'sk_test_abc123');
        $this->actingAs(User::factory()->create());
    }

    /** @param array<string, mixed> $settings */
    private function stripe(array $settings = [], int $available = 50000): MockInterface
    {
        return $this->mock(StripeService::class, function (MockInterface $mock) use ($settings, $available) {
            $mock->shouldReceive('currency')->andReturn('usd');
            $mock->shouldReceive('payoutSettings')->andReturn($settings + [
                'interval' => 'manual', 'weekly_days' => [], 'monthly_days' => [], 'status' => 'enabled', 'delay_days' => 2,
            ]);
            $mock->shouldReceive('balance')->andReturn(['available' => ['usd' => $available], 'pending' => ['usd' => 1000]]);
            $mock->shouldReceive('availableBalance')->andReturn($available);
        });
    }

    public function test_page_shows_schedule_and_balance(): void
    {
        $this->stripe(['interval' => 'weekly', 'weekly_days' => ['friday']]);

        $this->get('/payouts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payouts/Index')
            ->where('settings.interval', 'weekly')
            ->where('settings.weekly_days.0', 'friday')
            ->where('available', 50000)
            ->where('pending', 1000));
    }

    public function test_schedule_changes_are_sent_to_stripe(): void
    {
        $mock = $this->stripe();
        $mock->shouldReceive('updatePayoutSchedule')->once()->with('monthly', null, 15)->andReturn([]);

        $this->from('/payouts')->post('/payouts/schedule', ['interval' => 'monthly', 'month_day' => 15])
            ->assertRedirect('/payouts')->assertSessionHas('success');
    }

    public function test_weekly_schedule_needs_a_weekday(): void
    {
        $this->stripe()->shouldNotReceive('updatePayoutSchedule');

        $this->from('/payouts')->post('/payouts/schedule', ['interval' => 'weekly'])->assertSessionHasErrors('weekday');
    }

    public function test_payout_is_refused_while_schedule_is_automatic(): void
    {
        $this->stripe(['interval' => 'daily'])->shouldNotReceive('createPayout');

        $this->from('/payouts')->post('/payouts', ['amount' => '100', 'confirm' => '1'])->assertSessionHasErrors('amount');
    }

    public function test_payout_cannot_exceed_the_available_balance(): void
    {
        $this->stripe([], 5000)->shouldNotReceive('createPayout');

        $this->from('/payouts')->post('/payouts', ['amount' => '50.01', 'confirm' => '1'])->assertSessionHasErrors('amount');
    }

    public function test_payout_must_be_confirmed(): void
    {
        $this->stripe()->shouldNotReceive('createPayout');

        $this->from('/payouts')->post('/payouts', ['amount' => '100'])->assertSessionHasErrors('confirm');
    }

    public function test_confirmed_payout_is_created_in_cents(): void
    {
        $this->stripe()->shouldReceive('createPayout')->once()->with(12345, 'usd', 'October income')->andReturn(new Payout);

        $this->from('/payouts')->post('/payouts', ['amount' => '123.45', 'description' => 'October income', 'confirm' => '1'])
            ->assertRedirect('/payouts')->assertSessionHas('success');
    }
}
