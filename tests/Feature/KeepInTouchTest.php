<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\Touch;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\KeepInTouch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeepInTouchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00', 'Asia/Dhaka'));
    }

    private function person(string $name, string $window, array $extra = []): Touch
    {
        $this->post('/personal/keep-in-touch', ['name' => $name, 'emoji' => '🙂', 'every_days' => 1, 'window' => $window] + $extra)->assertSessionHasNoErrors();

        return Touch::where('name', $name)->sole();
    }

    private function localRemindAt(Touch $touch): string
    {
        return $touch->fresh()->remind_at->setTimezone('Asia/Dhaka')->format('H:i');
    }

    public function test_reminders_are_spread_over_the_day_not_all_at_once(): void
    {
        $ma = $this->person('Ma', 'evening');
        $baba = $this->person('Baba', 'evening');
        $shoshur = $this->person('Shoshur', 'afternoon');

        $run = app(KeepInTouch::class);
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 08:00', 'Asia/Dhaka')));

        $this->assertSame('15:00', $this->localRemindAt($shoshur));
        $evening = collect([$this->localRemindAt($ma), $this->localRemindAt($baba)])->sort()->values()->all();
        $this->assertSame(['20:00', '20:40'], $evening, 'two evening calls are 40 minutes apart');

        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 15:00', 'Asia/Dhaka')));
        $this->assertStringStartsWith('📞 Call Shoshur', AppNotification::latest('id')->first()->title);
        $this->assertStringContainsString('k='.$shoshur->id, AppNotification::latest('id')->first()->url);

        // Once more after two hours, then not again today.
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 16:30', 'Asia/Dhaka')));
        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 17:01', 'Asia/Dhaka')));
        $this->assertNull($shoshur->fresh()->remind_at);
    }

    public function test_my_own_time_for_a_person(): void
    {
        $abbu = $this->person('Abbu', '20:00');
        $vaiya = $this->person('Vaiya', '13:00');
        $this->post('/personal/keep-in-touch', ['name' => 'X', 'every_days' => 2, 'window' => '25:00'])->assertSessionHasErrors('window');

        app(KeepInTouch::class)->run(CarbonImmutable::parse('2026-10-25 08:00', 'Asia/Dhaka'));

        $this->assertSame('20:00', $this->localRemindAt($abbu));
        $this->assertSame('13:00', $this->localRemindAt($vaiya));
    }

    public function test_a_time_already_passed_today_waits_for_tomorrow_no_catch_up(): void
    {
        $vaiya = $this->person('Vaiya', '13:00');
        $run = app(KeepInTouch::class);

        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 17:10', 'Asia/Dhaka')));
        $this->assertNull($vaiya->fresh()->remind_at, 'not a few minutes from now');
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 18:00', 'Asia/Dhaka')));

        $run->run(CarbonImmutable::parse('2026-10-26 08:00', 'Asia/Dhaka'));
        $this->assertSame('13:00', $this->localRemindAt($vaiya));
    }

    public function test_learn_it_uses_the_hour_i_usually_talk_to_them(): void
    {
        $vai = $this->person('Vai', 'auto');
        $run = app(KeepInTouch::class);
        foreach (['2026-10-20 21:10', '2026-10-21 21:30', '2026-10-22 12:00', '2026-10-23 21:05'] as $at) {
            $run->mark($vai->fresh(), CarbonImmutable::parse($at, 'Asia/Dhaka'));
        }
        $this->assertSame(21, KeepInTouch::usualHour($vai->fresh()));

        $run->run(CarbonImmutable::parse('2026-10-25 08:00', 'Asia/Dhaka'));
        $this->assertSame('21:00', $this->localRemindAt($vai));
    }

    public function test_an_answered_call_in_the_call_log_counts_as_talking(): void
    {
        $ma = $this->person('Ma', 'evening', ['phone' => '01711-223344']);
        $url = '/app/followups/'.AutoEntries::token();
        $event = ['action' => 'called_back', 'source' => 'call', 'person' => 'Ma', 'phone' => '+8801711223344', 'at' => now()->getTimestampMs()];

        $this->postJson($url, ['events' => [$event + ['duration' => 0]]])->assertOk();
        $this->assertNull($ma->fresh()->last_talked_at, 'an unanswered call is not talking');

        $this->postJson($url, ['events' => [$event + ['duration' => 95]]])->assertOk();
        $this->assertNotNull($ma->fresh()->last_talked_at);
        $this->assertSame(0, app(KeepInTouch::class)->run(CarbonImmutable::parse('2026-10-25 20:30', 'Asia/Dhaka')));
    }

    public function test_talked_and_later_from_the_phone_notification(): void
    {
        $ma = $this->person('Ma', 'evening');
        $token = AutoEntries::token();

        $this->postJson("/app/touch/{$token}/{$ma->id}/later")->assertOk();
        $this->assertSame('09:00', $this->localRemindAt($ma));

        $this->postJson("/app/touch/{$token}/{$ma->id}/done")->assertOk();
        $this->assertNotNull($ma->fresh()->last_talked_at);
        $this->assertNull($ma->fresh()->remind_at);
        $this->postJson("/app/touch/wrong/{$ma->id}/done")->assertNotFound();

        $this->get('/personal/keep-in-touch')->assertInertia(fn ($page) => $page->component('Personal/KeepInTouch')
            ->where('people.0.days_since', 0)->where('people.0.due', false));
    }
}
