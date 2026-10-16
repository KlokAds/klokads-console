<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the web installer has run, every page leads to /install;
 * afterwards the installer is closed for good.
 *
 * Registered as global middleware so it runs before auth and sessions.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installing = $request->is('install', 'install/*');

        if (! Installer::isInstalled() && ! $installing && ! $request->is('up', 'build/*')) {
            return redirect('/install');
        }

        if (Installer::isInstalled() && $installing) {
            return redirect('/login');
        }

        return $next($request);
    }
}
