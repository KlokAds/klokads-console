<?php

namespace App\Services;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * My tasks: at the time I set, a reminder; still not done → again every 3 hours (3 times at
 * most). A repeating task (daily / weekly / monthly) moves on to its next time once done.
 */
class Tasks
{
    public const AGAIN_AFTER_MINUTES = 180;

    public const MAX_REMINDERS = 3;

    public const REPEATS = ['none' => 'Once', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    public function __construct(private Notify $notify) {}

    public function run(?CarbonImmutable $now = null): int
    {
        // Stored times are UTC: compare in UTC whatever zone the clock came in.
        $now = ($now ?? CarbonImmutable::now())->utc();
        $sent = 0;

        foreach (Task::whereNull('done_at')->whereNotNull('remind_at')->where('remind_at', '<=', $now)->where('reminded', '<', self::MAX_REMINDERS)->get() as $task) {
            $late = $task->reminded > 0 ? ' — still not done' : '';
            $this->notify->send('reminder', "📝 {$task->title}{$late}", trim(($task->note ? $task->note.' · ' : '').'Tap Done when it is finished.'),
                route('personal.tasks.index', ['t' => $task->id]), 'klok-task');
            $task->update([
                'reminded' => $task->reminded + 1,
                'remind_at' => $task->reminded + 1 < self::MAX_REMINDERS ? $now->addMinutes(self::AGAIN_AFTER_MINUTES) : null,
            ]);
            $sent++;
        }

        return $sent;
    }

    /** Done: a repeating task comes back at its next time, a one-off is finished. */
    public function done(Task $task): void
    {
        if ($task->repeat !== 'none' && $task->due_at) {
            $next = CarbonImmutable::parse($task->due_at);
            do {
                $next = match ($task->repeat) {
                    'daily' => $next->addDay(),
                    'weekly' => $next->addWeek(),
                    default => $next->addMonthNoOverflow(),
                };
            } while ($next->lte(now()));
            $task->update(['due_at' => $next, 'remind_at' => $next, 'reminded' => 0, 'done_at' => null]);

            return;
        }
        $task->update(['done_at' => now(), 'remind_at' => null]);
    }

    public function later(Task $task, int $minutes = 60): void
    {
        $task->update(['remind_at' => now()->addMinutes($minutes), 'reminded' => min($task->reminded, self::MAX_REMINDERS - 1), 'done_at' => null]);
    }
}
