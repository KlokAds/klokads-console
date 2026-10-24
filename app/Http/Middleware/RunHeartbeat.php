<?php

namespace App\Http\Middleware;

use App\Services\Heartbeat;
use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's own clock: after any page or webhook has been sent to the browser,
 * run the scheduled jobs (at most every 5 minutes). The visitor never waits for it.
 */
class RunHeartbeat
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** Runs after the response has gone out (PHP-FPM / LiteSpeed finish the request first). */
    public function terminate(Request $request, Response $response): void
    {
        // Not while testing, and not before the installer has finished.
        if (app()->runningUnitTests() || ! Installer::isInstalled()) {
            return;
        }

        ignore_user_abort(true);
        app(Heartbeat::class)->maybeRun();
    }
}
