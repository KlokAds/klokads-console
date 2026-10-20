<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\StripeService;
use App\Support\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

abstract class Controller
{
    /**
     * Validation rules for the shared "ad budget" billing fields.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function budgetRules(): array
    {
        return [
            'billing' => ['nullable', 'in:standard,budget,commission'],
            'pass_fees' => ['boolean'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
            'statement_descriptor' => ['nullable', 'string', 'max:'.StripeService::descriptorMaxLength(), 'regex:/^[A-Za-z0-9 .\-&]*[A-Za-z][A-Za-z0-9 .\-&]*$/'],
        ];
    }

    /**
     * When the form uses ad-budget billing, the amount is what Klok Ads should net:
     * the client pays card + payout fees on top and the commission is a share of the net.
     *
     * @return array<string, mixed>|null a Pricing::breakdown(), or null for standard billing
     */
    protected function budgetFromRequest(Request $request, int $net, ?Client $client): ?array
    {
        if (! in_array($request->input('billing'), ['budget', 'commission'], true)) {
            return null;
        }

        $pricing = Pricing::forClient($client);
        if ($request->filled('commission_rate')) {
            $pricing->commissionRate = (float) $request->input('commission_rate');
        }

        // Commission only: the amount entered is the client's own ad spend, and the client
        // always pays the card fees, so the full commission reaches us.
        return $request->input('billing') === 'commission'
            ? $pricing->commissionOnly($net)
            : $pricing->breakdown($net, $request->boolean('pass_fees', true));
    }

    /** Convert a decimal amount from a form (e.g. "1250.50") to cents. */
    protected function toCents(float|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Run a Stripe call and turn API errors into a flash message instead of a 500.
     *
     * @param  callable(): mixed  $action  may return a RedirectResponse to override "back"
     */
    protected function stripeAction(callable $action, string $success): RedirectResponse
    {
        try {
            $response = $action();
        } catch (ApiErrorException|RuntimeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage())->withInput();
        }

        $redirect = $response instanceof RedirectResponse ? $response : back();

        return $redirect->with('success', $success);
    }
}
