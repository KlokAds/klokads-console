<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Services\MetaAds;
use App\Services\PrepaidBalance;
use App\Services\SpendBilling;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/** Meta token, and per client: ad account, campaign filter, auto-charge threshold, sync and charge. */
class SpendBillingController extends Controller
{
    public function saveToken(Request $request, MetaAds $meta): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:40', 'max:600']]);
        $old = Setting::get('meta_token');
        Setting::put('meta_token', trim($data['token']));

        try {
            $count = count($meta->adAccounts());
        } catch (Throwable $e) {
            Setting::put('meta_token', $old);

            return back()->with('error', $e->getMessage().' — the previous token was kept.');
        }

        return back()->with('success', "Meta connected — {$count} ad account".($count === 1 ? '' : 's').' visible.');
    }

    /** Overview of what the token sees, for the account picker. */
    public static function accountsFor(MetaAds $meta): array
    {
        if (! MetaAds::configured()) {
            return ['connected' => false, 'accounts' => [], 'error' => null];
        }

        try {
            return ['connected' => true, 'accounts' => $meta->adAccounts(), 'error' => null];
        } catch (Throwable $e) {
            return ['connected' => true, 'accounts' => [], 'error' => $e->getMessage()];
        }
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'meta_ad_account_id' => ['nullable', 'string', 'max:40', 'regex:/^(act_)?\d+$/'],
            'meta_campaign_filter' => ['nullable', 'string', 'max:120'],
            'spend_billing' => ['boolean'],
            'spend_threshold' => ['required', 'numeric', 'min:50', 'max:100000'],
            'billing_mode' => ['nullable', 'in:prepaid,postpaid'],
            'prepaid_buffer_days' => ['nullable', 'integer', 'between:1,60'],
            'prepaid_topup_days' => ['nullable', 'integer', 'between:1,90', 'gte:prepaid_buffer_days'],
            'prepaid_min_topup' => ['nullable', 'numeric', 'min:50', 'max:100000'],
        ], [
            'meta_ad_account_id.regex' => 'Use the ad account number, e.g. act_1170923540578070.',
            'prepaid_topup_days.gte' => 'Top up for at least as many days as the backup.',
        ]);

        $turningOn = ($data['spend_billing'] ?? false) && ! $client->spend_billing;
        $client->update([
            'meta_ad_account_id' => filled($data['meta_ad_account_id'] ?? null) ? MetaAds::act($data['meta_ad_account_id']) : null,
            'meta_campaign_filter' => $data['meta_campaign_filter'] ?? null,
            'spend_billing' => (bool) ($data['spend_billing'] ?? false),
            'spend_threshold' => $this->toCents($data['spend_threshold']),
            // Fields left out keep their current values.
            'billing_mode' => $data['billing_mode'] ?? $client->billing_mode,
            'prepaid_buffer_days' => $data['prepaid_buffer_days'] ?? $client->prepaid_buffer_days,
            'prepaid_topup_days' => $data['prepaid_topup_days'] ?? $client->prepaid_topup_days,
            'prepaid_min_topup' => isset($data['prepaid_min_topup']) ? $this->toCents($data['prepaid_min_topup']) : $client->prepaid_min_topup,
            // Count from today when auto-charge is first turned on — never bill old spend by surprise.
            'spend_billed_until' => $turningOn && ! $client->spend_billed_until ? CarbonImmutable::yesterday()->toDateString() : $client->spend_billed_until,
            'spend_error' => null,
        ]);

        return back()->with('success', ! $client->spend_billing ? 'Meta settings saved.' : ($client->billing_mode === 'prepaid'
            ? 'Prepaid balance is on — spend comes out of the balance and it tops up automatically.'
            : 'Auto-charge is on — it charges when unbilled spend reaches the threshold.'));
    }

    public function sync(Client $client, SpendBilling $billing): RedirectResponse
    {
        try {
            $billing->sync($client);
        } catch (Throwable $e) {
            $client->update(['spend_error' => mb_substr($e->getMessage(), 0, 500)]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Spend updated from Meta.');
    }

    /** Charge all unbilled finished days now, even below the threshold. */
    public function charge(Request $request, Client $client, SpendBilling $billing): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm the charge.']);

        $invoice = $billing->chargeIfDue($client->fresh(), manual: true);

        return $invoice
            ? back()->with('success', "Charged — invoice {$invoice->number}.")
            : back()->with('error', $client->fresh()->spend_error ?: 'Nothing to charge yet (only finished days are billed).');
    }

    /** Prepaid: charge a top-up of any amount now. */
    public function topUp(Request $request, Client $client, PrepaidBalance $prepaid): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:10', 'max:100000'], 'confirm' => ['accepted']],
            ['confirm.accepted' => 'Tick the box to confirm the charge.']);

        $invoice = $prepaid->topUpIfLow($client->fresh(), $this->toCents($data['amount']));

        return $invoice
            ? back()->with('success', "Topped up — invoice {$invoice->number}.")
            : back()->with('error', $client->fresh()->spend_error ?: 'The top-up did not go through.');
    }

    /** Prepaid: money received another way (e.g. bank transfer), or a correction (negative). */
    public function adjust(Request $request, Client $client, PrepaidBalance $prepaid): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000'], 'note' => ['required', 'string', 'max:150']]);
        $prepaid->adjust($client, $this->toCents($data['amount']), $data['note']);

        return back()->with('success', 'Balance updated.');
    }

    /**
     * Data for the client page: this month by campaign, unbilled, last charge.
     *
     * @return array<string, mixed>
     */
    public static function summary(Client $client): array
    {
        $today = CarbonImmutable::today();
        $month = MetaSpendDay::where('client_id', $client->id)->where('date', '>=', $today->startOfMonth()->toDateString())->get();
        $unbilled = app(SpendBilling::class)->unbilled($client);
        $todaySpend = MetaSpendDay::where('client_id', $client->id)->whereDate('date', $today)->whereNull('invoice_id')->get();

        $prepaid = app(PrepaidBalance::class);

        return [
            'prepaid' => $prepaid->status($client) + [
                'entries' => ClientBalanceEntry::where('client_id', $client->id)->latest('date')->latest('id')->limit(20)->get()
                    ->map(fn ($e) => $e->only('id', 'kind', 'amount', 'spend', 'commission', 'note', 'invoice_id') + ['date' => $e->date->toDateString()]),
            ],
            'settings' => $client->only('meta_ad_account_id', 'meta_campaign_filter', 'spend_billing', 'billing_mode', 'prepaid_buffer_days', 'prepaid_topup_days', 'prepaid_min_topup') + [
                'spend_threshold' => $client->spend_threshold,
                'billed_until' => $client->spend_billed_until?->toDateString(),
                'synced_at' => $client->spend_synced_at?->toIso8601String(),
                'error' => $client->spend_error,
            ],
            'currency' => $month->first()?->currency ?? $unbilled->first()?->currency,
            'campaigns' => $month->groupBy('campaign_id')->map(fn ($days) => [
                'name' => $days->first()->campaign_name,
                'month' => (int) $days->sum('spend'),
                'today' => (int) $days->where('date', $today)->sum('spend'),
                'unbilled' => (int) $days->whereNull('invoice_id')->filter(fn ($d) => $d->date->lt($today) && (! $client->spend_billed_until || $d->date->gt($client->spend_billed_until)))->sum('spend'),
                'impressions' => (int) $days->sum('impressions'),
                'clicks' => (int) $days->sum('clicks'),
            ])->sortByDesc('month')->values(),
            'unbilled' => (int) $unbilled->sum('spend'),
            'today' => (int) $todaySpend->sum('spend'),
            'month' => (int) $month->sum('spend'),
        ];
    }
}
