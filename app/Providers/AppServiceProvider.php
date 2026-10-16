<?php

namespace App\Providers;

use App\Support\Installer;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Outgoing mail uses the SMTP account saved in Settings (billing@klokads.com).
        if (Installer::isInstalled()) {
            try {
                MailSettings::apply();
            } catch (Throwable) {
                // No database yet (fresh install) — keep the default mailer.
            }
        }
    }
}
