<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\Tasks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Dhaka'));
    }

    public function test_a_task_reminds_at_my_time_and_again_until_done(): void
    {
        $this->post('/personal/tasks', ['title' => 'Call the landlord', 'date' => '2026-10-25', 'time' => '15:00', 'repeat' => 'none'])->assertSessionHasNoErrors();
        $task = Task::sole();
        $this->assertSame('2026-10-25 09:00:00', $task->due_at->utc()->format('Y-m-d H:i:s'), '3 pm in Dhaka');

        $run = app(Tasks::class);
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 14:59', 'Asia/Dhaka')));
        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 15:00', 'Asia/Dhaka')));
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 17:00', 'Asia/Dhaka')));
        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 18:01', 'Asia/Dhaka')));
        $this->assertSame('📝 Call the landlord — still not done', AppNotification::latest('id')->first()->title);
        $this->assertStringContainsString('t='.$task->id, AppNotification::latest('id')->first()->url);

        // Done from the phone notification: no more reminders.
        $this->postJson('/app/tasks/'.AutoEntries::token()."/{$task->id}/done")->assertOk();
        $this->assertNotNull($task->fresh()->done_at);
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 21:05', 'Asia/Dhaka')));
        $this->postJson("/app/tasks/wrong/{$task->id}/done")->assertNotFound();
    }

    public function test_a_repeating_task_moves_on_to_its_next_time_when_done(): void
    {
        $this->post('/personal/tasks', ['title' => 'Pay the internet bill', 'date' => '2026-10-25', 'time' => '09:00', 'repeat' => 'monthly']);
        $task = Task::sole();

        $this->post("/personal/tasks/{$task->id}/done");

        $task->refresh();
        $this->assertNull($task->done_at);
        $this->assertSame('2026-11-25 09:00', $task->due_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->get('/personal/tasks')->assertInertia(fn ($page) => $page->component('Personal/Tasks')->where('open.0.date', '2026-11-25')->where('open.0.time', '09:00'));
    }

    public function test_seen_on_the_phone_marks_the_notification_read(): void
    {
        $note = AppNotification::create(['kind' => 'credit', 'title' => '💰 bKash +৳500.00']);

        $this->postJson('/app/feed/'.AutoEntries::token().'/read?id='.$note->id)->assertOk();

        $this->assertNotNull($note->fresh()->read_at);
    }
}
