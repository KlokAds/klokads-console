<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StripeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

class SettingsController extends Controller
{
    public const AUTO_SYNC_MINUTES = 15;

    public function edit(): Response
    {
        $secret = Setting::get('stripe_secret');

        return Inertia::render('Settings/Index', [
            'stripe' => [
                'connected' => (bool) $secret,
                'mode' => $secret ? (str_contains($secret, '_live_') ? 'live' : 'test') : null,
                'masked' => $secret ? substr($secret, 0, 8).'••••'.substr($secret, -4) : null,
                'account' => Setting::get('stripe_account_name'),
                'hasWebhookSecret' => (bool) Setting::get('stripe_webhook_secret'),
                'webhookUrl' => route('stripe.webhook'),
                'lastSyncAt' => Setting::get('last_sync_at'),
            ],
            'business' => [
                'name' => Setting::get('business_name', 'Klok Ads'),
                'currency' => Setting::get('currency', 'sgd'),
            ],
        ]);
    }

    public function updateStripe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stripe_secret' => ['nullable', 'string', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_webhook_secret' => ['nullable', 'string', 'starts_with:whsec_'],
        ], [
            'stripe_secret.regex' => 'Use a Stripe secret key (sk_test_… / sk_live_…) or restricted key (rk_…).',
        ]);

        if (filled($data['stripe_secret'] ?? null)) {
            try {
                $account = (new StripeClient($data['stripe_secret']))->accounts->retrieve();
            } catch (ApiErrorException $e) {
                return back()->withErrors(['stripe_secret' => 'Stripe rejected this key: '.$e->getMessage()]);
            }

            Setting::put('stripe_secret', $data['stripe_secret']);
            Setting::put('stripe_account_name', $account->settings?->dashboard?->display_name ?: $account->email ?: $account->id);
            Setting::put('currency', $account->default_currency ?: 'sgd');
            Setting::put('last_sync_at', null);
            Cache::forget('stripe_balance');
        }

        if (filled($data['stripe_webhook_secret'] ?? null)) {
            Setting::put('stripe_webhook_secret', $data['stripe_webhook_secret']);
        }

        return back()->with('success', 'Stripe settings saved. Click "Sync now" to import your Stripe history.');
    }

    public function disconnect(): RedirectResponse
    {
        foreach (['stripe_secret', 'stripe_webhook_secret', 'stripe_account_name', 'last_sync_at'] as $key) {
            Setting::put($key, null);
        }
        Cache::forget('stripe_balance');

        return back()->with('success', 'Stripe disconnected. Synced history is kept.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        Setting::put('business_name', $data['name']);
        Setting::put('currency', strtolower($data['currency']));

        return back()->with('success', 'Business settings saved.');
    }

    public function sync(Request $request, StripeService $stripe): RedirectResponse
    {
        if ($request->boolean('full')) {
            Setting::put('last_sync_at', null);
        }

        @set_time_limit(300);

        return $this->stripeAction(function () use ($stripe) {
            $c = $stripe->sync();
            session()->flash('sync', $c);
        }, 'Synced from Stripe.');
    }

    /**
     * Quiet background sync the layout fires when data is older than 15 minutes,
     * so the console stays fresh without a server cron job.
     */
    public function autoSync(StripeService $stripe): RedirectResponse
    {
        $lastSync = Setting::get('last_sync_at');
        $isFresh = $lastSync && Carbon::parse($lastSync)->gt(now()->subMinutes(self::AUTO_SYNC_MINUTES));

        if (! StripeService::configured() || $isFresh) {
            return back();
        }

        // Only one sync at a time, even with several tabs open.
        $lock = Cache::lock('klok:auto-sync', 300);

        if (! $lock->get()) {
            return back();
        }

        ignore_user_abort(true);
        @set_time_limit(300);

        try {
            $stripe->sync();
        } catch (Throwable $e) {
            Log::warning('Auto-sync from Stripe failed: '.$e->getMessage());
        } finally {
            $lock->release();
        }

        return back();
    }
}
