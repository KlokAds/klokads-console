<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * One-time setup wizard: setup code → database → admin account → business & Stripe.
 */
class InstallController extends Controller
{
    private const SESSION_KEY = 'install_verified';

    public function show(Request $request): Response
    {
        $verified = (bool) $request->session()->get(self::SESSION_KEY);
        $databaseReady = $verified && Installer::databaseReady();

        $step = match (true) {
            ! $verified => 'code',
            ! $databaseReady => 'database',
            ! User::exists() => 'admin',
            default => 'business',
        };

        return Inertia::render('Install/Index', [
            'step' => $step,
            'requirements' => Installer::requirements(),
            'database' => [
                'host' => config('database.connections.mysql.host') ?: '127.0.0.1',
                'port' => (string) (config('database.connections.mysql.port') ?: '3306'),
                'database' => (string) config('database.connections.mysql.database'),
                'username' => (string) config('database.connections.mysql.username'),
            ],
            'appUrl' => config('app.url'),
        ]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        if (! Installer::checkCode($request->input('code'))) {
            return back()->withErrors(['code' => 'That setup code is not correct.']);
        }

        $request->session()->put(self::SESSION_KEY, true);

        return redirect()->route('install.show');
    }

    public function database(Request $request): RedirectResponse
    {
        $this->ensureVerified($request);

        $db = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if ($error = Installer::testConnection($db)) {
            return back()->withErrors(['database' => 'Could not connect: '.$error]);
        }

        try {
            Installer::configureDatabase($db);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        } catch (Throwable $e) {
            return back()->withErrors(['database' => 'Connected, but setting up the tables failed: '.$e->getMessage()]);
        }

        return redirect()->route('install.show')->with('success', 'Database connected and tables created.');
    }

    public function admin(Request $request): RedirectResponse
    {
        $this->ensureVerified($request);
        abort_if(User::exists(), 403, 'The admin account already exists.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        // The "hashed" cast on User stores the password as a bcrypt hash.
        $user = new User;
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);

        return redirect()->route('install.show')->with('success', 'Admin account created.');
    }

    public function business(Request $request): RedirectResponse
    {
        $this->ensureVerified($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'stripe_secret' => ['nullable', 'string', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
        ], [
            'stripe_secret.regex' => 'Use a Stripe secret key (sk_test_… / sk_live_…) or restricted key (rk_…).',
        ]);

        Setting::put('business_name', $data['name']);
        Setting::put('currency', strtolower($data['currency']));

        if (filled($data['stripe_secret'] ?? null)) {
            try {
                $account = (new StripeClient($data['stripe_secret']))->accounts->retrieve();
            } catch (ApiErrorException $e) {
                return back()->withErrors(['stripe_secret' => 'Stripe rejected this key: '.$e->getMessage()]);
            }

            // Stored encrypted (see Setting::SECRET), never hashed: the app must send it to Stripe.
            Setting::put('stripe_secret', $data['stripe_secret']);
            Setting::put('stripe_account_name', $account->settings?->dashboard?->display_name ?: $account->email ?: $account->id);
        }

        Installer::markInstalled();
        $request->session()->forget(self::SESSION_KEY);

        if (! Auth::check() && $user = User::first()) {
            Auth::login($user);
        }

        return redirect()->route('dashboard')->with('success', 'Klok Ads console is ready.');
    }

    private function ensureVerified(Request $request): void
    {
        abort_unless($request->session()->get(self::SESSION_KEY), 403, 'Enter the setup code first.');
    }
}
