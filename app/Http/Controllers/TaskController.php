<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\AutoEntries;
use App\Services\PersonalReminders;
use App\Services\Tasks;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** My tasks: write it down when I think of it, get reminded at my time. */
class TaskController extends Controller
{
    public function __construct(private Tasks $tasks) {}

    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $tz = PersonalReminders::timezone();
        $row = fn (Task $t) => [
            ...$t->only('id', 'title', 'note', 'repeat', 'reminded'),
            // In my own time zone, as I typed it.
            'date' => $t->due_at?->setTimezone($tz)->toDateString(),
            'time' => $t->due_at?->setTimezone($tz)->format('H:i'),
            'due_at' => $t->due_at?->toIso8601String(),
            'overdue' => ! $t->done_at && $t->due_at?->isPast(),
            'done_at' => $t->done_at?->toIso8601String(),
        ];

        return Inertia::render('Personal/Tasks', [
            'open' => Task::whereNull('done_at')->orderByRaw('due_at is null')->orderBy('due_at')->get()->map($row),
            'done' => Task::whereNotNull('done_at')->latest('done_at')->limit(30)->get()->map($row),
            'repeats' => Tasks::REPEATS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Task::create($this->validated($request));

        return back()->with('success', 'Task saved — I\'ll remind you.');
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $task->update($this->validated($request) + ['done_at' => null]);

        return back()->with('success', 'Task updated.');
    }

    public function done(Task $task): RedirectResponse
    {
        $this->tasks->done($task);

        return back()->with('success', $task->repeat !== 'none' ? 'Done — next one is set.' : 'Done ✓');
    }

    public function later(Request $request, Task $task): RedirectResponse
    {
        $this->tasks->later($task, (int) ($request->validate(['minutes' => ['nullable', 'integer', 'in:30,60,180,1440']])['minutes'] ?? 60));

        return back()->with('success', 'I\'ll remind you again.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();

        return back()->with('success', 'Task removed.');
    }

    /** Done / later from the buttons on the phone notification. */
    public function fromPhone(Request $request, string $token, Task $task, string $action): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        match ($action) {
            'done' => $this->tasks->done($task),
            'later' => $this->tasks->later($task, 60),
            default => abort(404),
        };

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'repeat' => ['required', Rule::in(array_keys(Tasks::REPEATS))],
        ]);
        // The date and time I picked are in my own time zone.
        $due = $data['date'] ? CarbonImmutable::parse($data['date'].' '.($data['time'] ?: '09:00'), PersonalReminders::timezone())->utc() : null;

        return ['title' => $data['title'], 'note' => $data['note'] ?? null, 'repeat' => $data['repeat'], 'due_at' => $due, 'remind_at' => $due, 'reminded' => 0];
    }
}
