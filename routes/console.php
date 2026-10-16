<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use App\Support\Installer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('klok:sync {--full : Re-import the last 365 days}', function (StripeService $stripe) {
    if (! StripeService::configured()) {
        $this->warn('Stripe is not connected yet (Settings page).');

        return;
    }

    if ($this->option('full')) {
        Setting::put('last_sync_at', null);
    }

    foreach ($stripe->sync() as $what => $count) {
        $this->line("{$what}: {$count}");
    }
})->purpose('Pull invoices, balance transactions, payouts and subscriptions from Stripe');

Artisan::command('klok:admin {email} {--name=Admin} {--password= : Leave empty to generate one}', function () {
    $password = $this->option('password') ?: Str::password(16, symbols: false);

    $user = User::firstOrNew(['email' => $this->argument('email')]);
    $user->forceFill([
        'name' => $this->option('name'),
        'password' => Hash::make($password),
        'email_verified_at' => $user->email_verified_at ?? now(),
    ])->save();

    $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin {$user->email}");

    if (! $this->option('password')) {
        $this->line("Password: {$password}");
    }
})->purpose('Create or reset the dashboard admin login');

Artisan::command('klok:install-code', function () {
    if (Installer::isInstalled()) {
        $this->warn('Already installed — the web installer is closed.');

        return;
    }

    $this->line('Setup code for '.config('app.url').'/install :');
    $this->info(Installer::setupCode());
})->purpose('Show the one-time code the web installer asks for');

Schedule::command('klok:sync')->everyFifteenMinutes()->withoutOverlapping();
