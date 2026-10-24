<?php

namespace Tests\Feature;

use App\Http\Middleware\RunHeartbeat;
use App\Models\Setting;
use App\Services\EmailInbox;
use App\Services\Heartbeat;
use App\Services\PersonalInvoicing;
use App\Services\PushNotifier;
use App\Services\Reminders;
use App\Services\SpendBilling;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_every_job_at_most_every_five_minutes_and_one_failure_never_stops_the_rest(): void
    {
        $calls = [];
        $this->mock(Reminders::class, function (MockInterface $m) use (&$calls) {
            $m->shouldReceive('run')->andReturnUsing(function () use (&$calls) {
                $calls[] = 'reminders';

                return ['sent' => 0, 'checked' => 0];
            });
        });
        $this->mock(SpendBilling::class, fn (MockInterface $m) => $m->shouldReceive('runAll')->andThrow(new RuntimeException('Meta down')));
        $this->mock(EmailInbox::class, fn (MockInterface $m) => $m->shouldReceive('poll')->andReturn(2));
        $this->mock(PersonalInvoicing::class, fn (MockInterface $m) => $m->shouldReceive('runRecurring')->andReturn(1));

        $beat = app(Heartbeat::class);
        $first = $beat->maybeRun();

        $this->assertSame(['sent' => 0, 'checked' => 0], $first['reminders']);
        $this->assertSame(['error', 2, 1], [$first['billing'], $first['emails'], $first['personal_invoices']]);
        $this->assertNotNull(Setting::get('heartbeat_at'));

        $this->assertNull($beat->maybeRun(), 'not again within 5 minutes');
        $this->travel(6)->minutes();
        $this->assertNotNull($beat->maybeRun());
        $this->assertCount(2, $calls);
    }

    public function test_runs_after_every_web_request_without_slowing_it(): void
    {
        $this->assertContains(RunHeartbeat::class, app(Kernel::class)->getMiddlewareGroups()['web']);
        $this->assertTrue(method_exists(RunHeartbeat::class, 'terminate'), 'runs after the response is sent');
    }

    public function test_wake_up_call_runs_jobs_and_is_never_doubled(): void
    {
        $this->mock(Reminders::class, fn (MockInterface $m) => $m->shouldReceive('run')->andReturn(['sent' => 0, 'checked' => 0]));
        $this->mock(SpendBilling::class, fn (MockInterface $m) => $m->shouldReceive('runAll')->andReturn([]));
        $this->mock(EmailInbox::class, fn (MockInterface $m) => $m->shouldReceive('poll')->andReturn(0));
        $this->mock(PersonalInvoicing::class, fn (MockInterface $m) => $m->shouldReceive('runRecurring')->andReturn(0));

        $url = '/reminders/ping/'.PushNotifier::cronToken();
        $this->getJson($url.'?wake=1')->assertOk()->assertJsonPath('reminders.checked', 0);
        $this->assertNotNull(Setting::get('heartbeat_woke_at'));
        $this->assertNull(Setting::get('cron_last_ping'), 'a wake-up is not an outside ping');

        $this->getJson($url.'?wake=1')->assertOk()->assertJsonPath('skipped', 'ran less than 4 minutes ago');
        $this->assertFalse(Heartbeat::canWake(), 'no background commands in tests');
    }
}
