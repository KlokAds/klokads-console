<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PersonalAccess;
use App\Http\Middleware\RunHeartbeat;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureInstalled::class);

        $middleware->alias(['personal' => PersonalAccess::class]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RunHeartbeat::class, // the app's own clock (no outside cron)
        ]);

        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'inbox/sms/*', 'hooks/app-release/*', 'app/config/*', 'app/test/*', 'app/followups/*', 'app/feed/*', 'app/tasks/*', 'app/touch/*']);

        // On the server the app's public/ folder is also reachable under the main site
        // (klokads.com/console); only answer requests for the APP_URL host.
        $middleware->trustHosts();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
