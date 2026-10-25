<?php

namespace App\Http\Controllers;

use App\Models\Touch;
use App\Services\AutoEntries;
use App\Services\KeepInTouch;
use App\Services\PersonalReminders;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Keep in touch with family: who, how often, when it suits them. */
class TouchController extends Controller
{
    public function __construct(private KeepInTouch $touch) {}

    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $tz = PersonalReminders::timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        return Inertia::render('Personal/KeepInTouch', [
            'people' => Touch::orderBy('sort')->orderBy('name')->get()->map(fn (Touch $t) => [
                ...$t->only('id', 'name', 'emoji', 'phone', 'every_days', 'window', 'paused'),
                'last_talked_at' => $t->last_talked_at?->toIso8601String(),
                'days_since' => $t->last_talked_at ? (int) $t->last_talked_at->setTimezone($tz)->startOfDay()->diffInDays($today) : null,
                'due' => KeepInTouch::due($t, $today),
                'remind_at' => $t->remind_at?->setTimezone($tz)->format('H:i'),
                'usual_hour' => KeepInTouch::usualHour($t),
            ]),
            'suggestions' => collect(KeepInTouch::SUGGESTIONS)->reject(fn ($s) => Touch::where('name', $s['name'])->exists())->values(),
            'windows' => KeepInTouch::WINDOWS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Touch::create($this->validated($request) + ['sort' => (int) Touch::max('sort') + 1]);

        return back()->with('success', 'Added — I\'ll remind you when it\'s time to call.');
    }

    public function update(Request $request, Touch $touch): RedirectResponse
    {
        $touch->update($this->validated($request) + ['planned_on' => null, 'remind_at' => null]);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Touch $touch): RedirectResponse
    {
        $touch->delete();

        return back()->with('success', 'Removed.');
    }

    public function talked(Touch $touch): RedirectResponse
    {
        $this->touch->mark($touch);

        return back()->with('success', "Talked to {$touch->name} ✓");
    }

    /** Talked ✓ / 1 hour later from the phone notification. */
    public function fromPhone(string $token, Touch $touch, string $action): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        match ($action) {
            'done' => $this->touch->mark($touch),
            'later' => $this->touch->later($touch, 60),
            default => abort(404),
        };

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'phone' => ['nullable', 'string', 'max:30'],
            'every_days' => ['required', 'integer', 'between:1,90'],
            'window' => ['required', Rule::in([...array_keys(KeepInTouch::WINDOWS), 'auto'])],
            'paused' => ['boolean'],
        ]);
    }
}
