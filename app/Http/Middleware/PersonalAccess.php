<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The personal side: only the owner may open it, and when a PIN is set it must have
 * been entered within the last 15 minutes (each visit keeps it unlocked a bit longer).
 */
class PersonalAccess
{
    public const IDLE_MINUTES = 15;

    /** Owner and (if a PIN is set) recently unlocked — for code that serves both sides. */
    public static function allowed(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->is_owner && (! $user->pin_hash || (int) $request->session()->get('personal_unlocked_until', 0) >= now()->timestamp);
    }

    /** Stop here unless allowed (redirects to the PIN screen when only the PIN is missing). */
    public static function guard(Request $request): void
    {
        abort_unless($request->user()?->is_owner, 403, 'The personal side is private to its owner.');
        if (! self::allowed($request)) {
            // App calls (e.g. offline entries syncing) get "locked", never a page they'd mistake for success.
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                throw new HttpResponseException(response()->json(['message' => 'Locked — enter your PIN.'], 423));
            }
            $request->session()->put('personal_intended', $request->fullUrl());
            throw new HttpResponseException(redirect()->route('personal.unlock'));
        }
        $request->session()->put('personal_unlocked_until', now()->addMinutes(self::IDLE_MINUTES)->timestamp);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->is_owner, 403, 'The personal side is private to its owner.');

        if ($user->pin_hash) {
            $until = (int) $request->session()->get('personal_unlocked_until', 0);
            if ($until < now()->timestamp) {
                if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                    return response()->json(['message' => 'Locked — enter your PIN.'], 423);
                }
                $request->session()->put('personal_intended', $request->fullUrl());

                return redirect()->route('personal.unlock');
            }
            $request->session()->put('personal_unlocked_until', now()->addMinutes(self::IDLE_MINUTES)->timestamp);
        }

        return $next($request);
    }
}
