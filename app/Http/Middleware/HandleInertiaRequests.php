<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\Installer;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Before installation there is no database to read settings from.
        $installed = Installer::isInstalled();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'appName' => $installed ? Setting::get('business_name', 'Klok Ads') : 'Klok Ads',
            'stripeStatus' => fn () => ! $installed ? [] : [
                'mode' => ($key = Setting::get('stripe_secret')) ? (str_contains($key, '_live_') ? 'live' : 'test') : null,
                'lastSyncAt' => Setting::get('last_sync_at'),
            ],
            // Fee and commission rates, for live "client pays / you receive" previews in forms.
            'pricing' => fn () => $installed ? Pricing::forClient()->toArray() : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'card_link' => fn () => $request->session()->get('card_link'),
            ],
        ];
    }
}
