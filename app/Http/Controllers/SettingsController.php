<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Airwallex;
use App\Services\MetaAds;
use App\Services\Notify;
use App\Services\StripeService;
use App\Support\Business;
use App\Support\Catalog;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
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
                'publishable' => ($pk = Setting::get('stripe_publishable')) ? substr($pk, 0, 12).'…'.substr($pk, -4) : null,
                'webhookUrl' => route('stripe.webhook'),
                'lastSyncAt' => Setting::get('last_sync_at'),
            ],
            'business' => Business::profile() + [
                'currency' => Setting::get('currency', 'sgd'),
                'currencyFromStripe' => (bool) $secret,
            ],
            'gst' => Business::gst(),
            'mail' => MailSettings::current() + ['configured' => MailSettings::configured()],
            'templates' => [
                'options' => Catalog::options(Catalog::TEMPLATES),
                'defaults' => collect(Catalog::DEFAULT_TEMPLATE_FOR)->map(fn ($t, $service) => [
                    'service' => $service,
                    'label' => Catalog::SERVICES[$service] ?? $service,
                    'template' => Business::templateFor($service),
                ])->values(),
            ],
            'descriptors' => [
                'with_month' => Setting::get('descriptor_with_month', '1') === '1',
                'prefix' => Setting::get('descriptor_prefix'),
                'max' => StripeService::descriptorMaxLength(),
                'items' => collect(StripeService::DEFAULT_DESCRIPTORS)->map(fn ($default, $key) => [
                    'key' => $key,
                    'label' => Catalog::SERVICES[$key] ?? $key,
                    'value' => StripeService::descriptorFor($key),
                ])->values(),
            ],
            'flow' => [
                'meta_via_pilot' => MetaAds::viaPilot(),
                'airwallex' => Airwallex::configured(),
                'airwallex_client_id' => Setting::get('airwallex_client_id'),
                'airwallex_synced_at' => Setting::get('airwallex_synced_at'),
                'airwallex_error' => Setting::get('airwallex_error'),
                'airwallex_balances' => json_decode((string) Setting::get('airwallex_balances'), true) ?: [],
                'meta_client' => Airwallex::metaClient()?->only('id', 'name', 'company'),
            ],
            'pricing' => [
                'commission_rate' => (float) Setting::get('commission_rate', 20),
                'card_fee_percent' => (float) Setting::get('card_fee_percent', 2.9),
                'card_fee_fixed' => (int) Setting::get('card_fee_fixed', 30),
                'payout_fee_percent' => (float) Setting::get('payout_fee_percent', 0.3),
            ],
        ]);
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:150'],
            'port' => ['required', 'integer', Rule::in([25, 465, 587, 2525])],
            'username' => ['required', 'string', 'max:150'],
            'password' => [MailSettings::current()['has_password'] ? 'nullable' : 'required', 'string', 'max:200'],
            'from_address' => ['required', 'email', 'max:150'],
            'from_name' => ['required', 'string', 'max:100'],
            'auto_receipts' => ['boolean'],
            'auto_invoices' => ['boolean'],
        ]);

        Setting::put('smtp_host', $data['host']);
        Setting::put('smtp_port', (string) $data['port']);
        Setting::put('smtp_username', $data['username']);
        if (filled($data['password'] ?? null)) {
            Setting::put('smtp_password', $data['password']);
        }
        Setting::put('mail_from_address', $data['from_address']);
        Setting::put('mail_from_name', $data['from_name']);
        Setting::put('mail_auto_receipts', $request->boolean('auto_receipts') ? '1' : '0');
        Setting::put('mail_auto_invoices', $request->boolean('auto_invoices') ? '1' : '0');

        return back()->with('success', 'Email settings saved — send a test email to check them.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        if (! MailSettings::configured()) {
            return back()->with('error', 'Save your SMTP details first.');
        }

        MailSettings::apply();
        $to = $request->user()->email;

        try {
            Mail::raw("This is a test email from your Klok Ads console.\n\nIf you received it, invoices and receipts will be sent from ".MailSettings::current()['from_address'].'.', function ($message) use ($to) {
                $message->to($to)->subject('Klok Ads console — test email');
            });
        } catch (Throwable $e) {
            return back()->with('error', 'Test email failed: '.$e->getMessage());
        }

        return back()->with('success', "Test email sent to {$to} — check your inbox.");
    }

    /** Airwallex read-only API key: checked by reading the balance before it is kept. */
    public function updateAirwallex(Request $request, Airwallex $airwallex): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'api_key' => [Setting::get('airwallex_api_key') ? 'nullable' : 'required', 'string', 'max:300'],
        ]);
        $old = [Setting::get('airwallex_client_id'), Setting::get('airwallex_api_key')];
        Setting::put('airwallex_client_id', trim($data['client_id']));
        if (filled($data['api_key'] ?? null)) {
            Setting::put('airwallex_api_key', trim($data['api_key']));
        }

        try {
            $balances = $airwallex->balances();
        } catch (Throwable $e) {
            Setting::put('airwallex_client_id', $old[0]);
            Setting::put('airwallex_api_key', $old[1]);

            return back()->with('error', $e->getMessage().' — nothing was changed.');
        }
        Setting::put('airwallex_balances', json_encode($balances));
        $airwallex->run(force: true);

        return back()->with('success', 'Airwallex connected — '.(collect($balances)->map(fn ($c, $cur) => Notify::money($c, $cur))->implode(', ') ?: 'no balance yet').'.');
    }

    public function disconnectAirwallex(): RedirectResponse
    {
        foreach (['airwallex_client_id', 'airwallex_api_key', 'airwallex_balances', 'airwallex_error', 'airwallex_synced_at'] as $key) {
            Setting::put($key, null);
        }

        return back()->with('success', 'Airwallex disconnected.');
    }

    public function updateGst(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'rate' => ['required', 'numeric', 'between:0,30'],
            'reg_no' => ['nullable', 'required_if:enabled,true', 'string', 'max:30'],
            'label' => ['required', 'string', 'max:10'],
        ], ['reg_no.required_if' => 'Add your GST registration number before switching GST on.']);

        Setting::put('gst_enabled', $request->boolean('enabled') ? '1' : '0');
        Setting::put('gst_rate', (string) (float) $data['rate']);
        Setting::put('gst_reg_no', $data['reg_no'] ?? null);
        Setting::put('gst_label', $data['label']);

        return back()->with('success', $request->boolean('enabled')
            ? "{$data['label']} {$data['rate']}% is on — new invoices and charges include it."
            : "{$data['label']} is off — invoices won't include tax.");
    }

    public function updateTemplates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_for' => ['required', 'array'],
            'template_for.*' => ['required', Rule::in(array_keys(Catalog::TEMPLATES))],
        ]);

        foreach (array_intersect_key($data['template_for'], Catalog::DEFAULT_TEMPLATE_FOR) as $service => $template) {
            Setting::put("template_for_{$service}", $template);
        }

        return back()->with('success', 'Default templates saved.');
    }

    public function updateDescriptors(Request $request): RedirectResponse
    {
        $max = StripeService::descriptorMaxLength();
        $rules = collect(array_keys(StripeService::DEFAULT_DESCRIPTORS))->mapWithKeys(fn ($key) => [
            "descriptors.{$key}" => ['required', 'string', "max:{$max}", 'regex:/^[A-Za-z0-9 .\-&]*[A-Za-z][A-Za-z0-9 .\-&]*$/'],
        ])->all();

        $data = $request->validate($rules, [
            'descriptors.*.regex' => 'Use letters, numbers, spaces, . - & only, with at least one letter.',
            'descriptors.*.max' => "Keep it to {$max} characters so it fits on the bank statement.",
        ]);

        foreach ($data['descriptors'] as $key => $text) {
            Setting::put("descriptor_{$key}", StripeService::cleanDescriptor($text));
        }
        Setting::put('descriptor_with_month', $request->boolean('with_month') ? '1' : '0');

        return back()->with('success', 'Bank statement text saved — new charges will use it.');
    }

    public function updatePricing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'commission_rate' => ['required', 'numeric', 'between:0,100'],
            'card_fee_percent' => ['required', 'numeric', 'between:0,20'],
            'card_fee_fixed' => ['required', 'numeric', 'between:0,10'],
            'payout_fee_percent' => ['required', 'numeric', 'between:0,10'],
        ]);

        Setting::put('commission_rate', (string) (float) $data['commission_rate']);
        Setting::put('card_fee_percent', (string) (float) $data['card_fee_percent']);
        Setting::put('card_fee_fixed', (string) $this->toCents($data['card_fee_fixed']));
        Setting::put('payout_fee_percent', (string) (float) $data['payout_fee_percent']);

        return back()->with('success', 'Commission and fee settings saved.');
    }

    public function updateStripe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stripe_secret' => ['nullable', 'string', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_webhook_secret' => ['nullable', 'string', 'starts_with:whsec_'],
            'stripe_publishable' => ['nullable', 'string', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
        ], [
            'stripe_publishable.regex' => 'Use your publishable key (pk_test_… / pk_live_…).',
            'stripe_secret.regex' => 'Use a Stripe secret key (sk_test_… / sk_live_…) or restricted key (rk_…).',
        ]);

        if (filled($data['stripe_secret'] ?? null)) {
            try {
                $account = (new StripeClient($data['stripe_secret']))->accounts->retrieve();
            } catch (ApiErrorException $e) {
                return back()->withErrors(['stripe_secret' => 'Stripe rejected this key: '.$e->getMessage()]);
            }

            Setting::put('stripe_secret', $data['stripe_secret']);
            Setting::put('stripe_mode', str_contains($data['stripe_secret'], '_live_') ? 'live' : 'test');
            Setting::put('stripe_account_name', $account->settings?->dashboard?->display_name ?: $account->email ?: $account->id);
            // The reporting currency always follows the Stripe account's default currency.
            Setting::put('currency', strtolower($account->default_currency ?: 'sgd'));
            Setting::put('last_sync_at', null);
            StripeService::forgetBalance();
        }

        if (filled($data['stripe_webhook_secret'] ?? null)) {
            Setting::put('stripe_webhook_secret', $data['stripe_webhook_secret']);
        }

        if (filled($data['stripe_publishable'] ?? null)) {
            $secret = Setting::get('stripe_secret');
            $live = str_contains($data['stripe_publishable'], '_live_');

            if ($secret && $live !== str_contains($secret, '_live_')) {
                return back()->withErrors(['stripe_publishable' => 'This publishable key is '.($live ? 'live' : 'test').' but your secret key is '.($live ? 'test' : 'live').' — use the matching pair.']);
            }

            Setting::put('stripe_publishable', $data['stripe_publishable']);
        }

        return back()->with('success', 'Stripe settings saved. Click "Sync now" to import your Stripe history.');
    }

    public function disconnect(): RedirectResponse
    {
        foreach (['stripe_secret', 'stripe_webhook_secret', 'stripe_publishable', 'stripe_account_name', 'last_sync_at'] as $key) {
            Setting::put($key, null);
        }
        StripeService::forgetBalance();

        return back()->with('success', 'Stripe disconnected. Synced history is kept.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $connected = StripeService::configured();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => [$connected ? 'nullable' : 'required', 'string', 'size:3'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'uen' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:150'],
        ]);

        Setting::put('business_name', $data['name']);
        foreach (['legal_name', 'uen', 'address', 'email', 'phone', 'website'] as $field) {
            Setting::put("business_{$field}", $data[$field] ?? null);
        }

        // While Stripe is connected the currency comes from the Stripe account.
        if (! $connected) {
            Setting::put('currency', strtolower($data['currency']));
        }

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
