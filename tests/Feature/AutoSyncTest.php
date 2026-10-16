<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private function autoSync(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/dashboard')
            ->post('/settings/auto-sync')
            ->assertRedirect('/dashboard');
    }

    public function test_stale_data_is_synced_in_the_background(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        Setting::put('last_sync_at', now()->subMinutes(20)->toIso8601String());

        $this->mock(StripeService::class)->shouldReceive('sync')->once()->andReturn([]);

        $this->autoSync();
    }

    public function test_never_synced_data_is_synced(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');

        $this->mock(StripeService::class)->shouldReceive('sync')->once()->andReturn([]);

        $this->autoSync();
    }

    public function test_fresh_data_is_left_alone(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        Setting::put('last_sync_at', now()->subMinutes(5)->toIso8601String());

        $this->mock(StripeService::class)->shouldNotReceive('sync');

        $this->autoSync();
    }

    public function test_nothing_happens_without_stripe(): void
    {
        $this->mock(StripeService::class)->shouldNotReceive('sync');

        $this->autoSync();
    }

    public function test_a_stripe_failure_does_not_break_the_page(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');

        $this->mock(StripeService::class)->shouldReceive('sync')->once()->andThrow(new \RuntimeException('Stripe is down'));

        $this->autoSync();
    }

    public function test_guests_cannot_trigger_a_sync(): void
    {
        $this->post('/settings/auto-sync')->assertRedirect('/login');
    }
}
