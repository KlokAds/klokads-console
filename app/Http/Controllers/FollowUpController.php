<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Services\FollowUps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Follow-ups: unseen messages and missed calls, what they're about, done / later / note. */
class FollowUpController extends Controller
{
    public function index(Request $request, FollowUps $followUps): Response
    {
        $request->session()->put('mode', 'personal');
        $row = fn (FollowUp $f) => $followUps->row($f);

        return Inertia::render('Personal/FollowUps', [
            'open' => FollowUp::open()->latest('last_at')->limit(100)->get()->map($row),
            'closed' => FollowUp::where(fn ($q) => $q->whereNotNull('seen_at')->orWhereNotNull('done_at'))->latest('updated_at')->limit(40)->get()->map($row),
            'sources' => FollowUps::SOURCES,
        ]);
    }

    public function done(FollowUp $followUp, FollowUps $followUps): RedirectResponse
    {
        $followUps->done($followUp);

        return back()->with('success', "Done — {$followUp->person}.");
    }

    /** Remind me again in a while (1 hour by default). */
    public function later(Request $request, FollowUp $followUp, FollowUps $followUps): RedirectResponse
    {
        $minutes = (int) ($request->validate(['minutes' => ['nullable', 'integer', 'in:30,60,180,1440']])['minutes'] ?? 60);
        $followUps->later($followUp, $minutes);

        return back()->with('success', 'I\'ll remind you '.($minutes >= 1440 ? 'tomorrow' : 'in '.($minutes >= 60 ? ($minutes / 60).' hour'.($minutes > 60 ? 's' : '') : "{$minutes} minutes")).'.');
    }

    public function note(Request $request, FollowUp $followUp): RedirectResponse
    {
        $followUp->update($request->validate(['note' => ['nullable', 'string', 'max:500']]));

        return back()->with('success', 'Note saved — it comes with the reminder.');
    }
}
