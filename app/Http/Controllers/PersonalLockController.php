<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PersonalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PIN lock for the personal side. */
class PersonalLockController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()?->is_owner, 403);
        if (! $request->user()->pin_hash) {
            return redirect()->route('personal.dashboard');
        }

        return Inertia::render('Personal/Unlock', ['name' => $request->user()->name, 'length' => $request->user()->pin_length ?: 4]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_owner, 403);
        $key = 'pin:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many tries — wait '.ceil(RateLimiter::availableIn($key) / 60).' min.']);
        }

        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        if (! Hash::check($data['pin'], $request->user()->pin_hash)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['pin' => 'Wrong PIN.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('personal_unlocked_until', now()->addMinutes(PersonalAccess::IDLE_MINUTES)->timestamp);

        return redirect()->to($request->session()->pull('personal_intended', route('personal.dashboard')));
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget('personal_unlocked_until');

        // Locked means the PIN screen, never the business side. With no PIN there is nothing to lock with yet.
        return $request->user()?->pin_hash
            ? redirect()->route('personal.unlock')
            : redirect()->route('notifications.index')->with('error', 'Set a PIN first — then Lock asks for it.');
    }

    /** Set, change or remove the PIN (needs the account password). */
    public function save(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_owner, 403);
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'pin' => ['nullable', 'digits:6', 'confirmed'],
        ], ['password.current_password' => 'That is not your account password.']);

        $pin = $data['pin'] ?? null;
        $request->user()->forceFill(['pin_hash' => filled($pin) ? Hash::make($pin) : null, 'pin_length' => filled($pin) ? strlen($pin) : null])->save();
        $request->session()->put('personal_unlocked_until', now()->addMinutes(PersonalAccess::IDLE_MINUTES)->timestamp);

        return back()->with('success', filled($data['pin'] ?? null) ? 'PIN set — the personal side now locks after 15 idle minutes.' : 'PIN removed.');
    }
}
