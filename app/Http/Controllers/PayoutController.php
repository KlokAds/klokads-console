<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\StripeService;
use App\Support\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Payout schedule (daily / weekly / monthly / manual) and one-click manual payouts.
 */
class PayoutController extends Controller
{
    public function index(StripeService $stripe): Response
    {
        $settings = null;
        $balance = null;
        $error = null;

        if (StripeService::configured()) {
            try {
                $settings = $stripe->payoutSettings();
                $balance = $stripe->balance();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $currency = $stripe->currency();

        return Inertia::render('Payouts/Index', [
            'connected' => StripeService::configured(),
            'settings' => $settings,
            'error' => $error,
            'currency' => $currency,
            'available' => (int) ($balance['available'][$currency] ?? 0),
            'pending' => (int) ($balance['pending'][$currency] ?? 0),
            'payouts' => Payout::latest('occurred_at')->paginate(20)->through(fn (Payout $p) => [
                ...$p->toArray(),
                'bank_fee' => $fee = Ledger::payoutFee($p->amount),
                'received' => $p->amount - $fee,
            ]),
            'bank' => [
                'fee_rate' => Ledger::bankFeeRate(),
                'account_id' => Setting::get('payout_account_id'),
                'accounts' => Account::where('scope', 'business')->where('archived', false)->where('currency', strtoupper($currency))->orderBy('name')->get(['id', 'name', 'currency']),
            ],
            'totals' => [
                'paid' => (int) Payout::where('status', 'paid')->sum('amount'),
                'in_transit' => (int) Payout::whereIn('status', ['pending', 'in_transit'])->sum('amount'),
            ],
            'weekdays' => StripeService::PAYOUT_WEEKDAYS,
        ]);
    }

    public function updateSchedule(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'interval' => ['required', Rule::in(StripeService::PAYOUT_INTERVALS)],
            'weekday' => ['nullable', 'required_if:interval,weekly', Rule::in(StripeService::PAYOUT_WEEKDAYS)],
            'month_day' => ['nullable', 'required_if:interval,monthly', 'integer', 'between:1,31'],
        ]);

        $label = match ($data['interval']) {
            'daily' => 'every business day',
            'weekly' => 'every '.ucfirst($data['weekday']),
            'monthly' => 'on day '.$data['month_day'].' of every month',
            'manual' => 'only when you click "Pay out now"',
        };

        return $this->stripeAction(
            fn () => $stripe->updatePayoutSchedule($data['interval'], $data['weekday'] ?? null, isset($data['month_day']) ? (int) $data['month_day'] : null),
            "Payout schedule saved: Stripe pays out {$label}."
        );
    }

    /** Bank's cut on each deposit (e.g. 0.3%) and which account payouts land in. */
    public function updateBank(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fee_rate' => ['required', 'numeric', 'between:0,10'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'business')],
        ]);

        Setting::put('bank_fee_rate', (string) $data['fee_rate']);
        Setting::put('payout_account_id', $data['account_id'] ? (string) $data['account_id'] : null);

        // Catch up on paid payouts that arrived before an account was chosen.
        Payout::where('status', 'paid')->orderBy('occurred_at')->each(fn (Payout $p) => Ledger::recordPayout($p));

        return back()->with('success', 'Bank deposit settings saved.');
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:100'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm this payout.']);

        $amount = $this->toCents($data['amount']);
        $currency = $stripe->currency();

        return $this->stripeAction(function () use ($stripe, $amount, $currency, $data) {
            if (($stripe->payoutSettings()['interval'] ?? null) !== 'manual') {
                throw ValidationException::withMessages(['amount' => 'Switch the payout schedule to "Manual" first — Stripe only allows manual payouts on a manual schedule.']);
            }

            $available = $stripe->availableBalance($currency);
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Only '.number_format($available / 100, 2).' '.strtoupper($currency).' is available to pay out right now.']);
            }

            $stripe->createPayout($amount, $currency, $data['description'] ?? null);
        }, 'Payout of '.number_format($amount / 100, 2).' '.strtoupper($currency).' sent to your bank.');
    }
}
