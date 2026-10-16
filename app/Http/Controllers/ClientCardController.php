<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\StripeService;
use App\Support\Business;
use App\Support\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * A client's saved cards: add (our own card form), make default, remove, charge an invoice.
 */
class ClientCardController extends Controller
{
    /** Our own branded link where the client adds a card (valid 7 days). */
    public function link(Client $client, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(function () use ($client, $stripe) {
            $stripe->ensureCustomer($client);
            session()->flash('card_link', StripeService::cardLink($client));
        }, 'Card link ready — send it to the client (valid for 7 days).');
    }

    /** Secret for the card popup inside the console ("Enter card now"). */
    public function enter(Client $client, StripeService $stripe): JsonResponse
    {
        if (! Setting::get('stripe_publishable')) {
            return response()->json(['message' => 'Add your Stripe publishable key in Settings → Stripe & payments first.'], 422);
        }

        try {
            return response()->json([
                'client_secret' => $stripe->cardSetupSecret($client),
                'publishable_key' => Setting::get('stripe_publishable'),
            ]);
        } catch (ApiErrorException|RuntimeException $e) {
            return response()->json(['message' => 'Stripe: '.$e->getMessage()], 422);
        }
    }

    /** Client-facing add-card page on our domain (signed, expiring link). */
    public function publicPage(Client $client, StripeService $stripe): Response
    {
        $secret = null;
        $error = null;

        try {
            $secret = Setting::get('stripe_publishable') ? $stripe->cardSetupSecret($client) : null;
        } catch (Throwable) {
            $error = 'Saving a card is temporarily unavailable. Please try again shortly.';
        }

        return Inertia::render('Public/AddCard', [
            'client' => ['name' => $client->name, 'company' => $client->company, 'email' => $client->email],
            'business' => Business::profile(),
            'setup' => ['client_secret' => $secret, 'publishable_key' => Setting::get('stripe_publishable'), 'error' => $error],
        ]);
    }

    /** Charge a saved card now for any amount (e.g. when the client asks). */
    public function chargeNow(Request $request, Client $client, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.5'],
            'description' => ['required', 'string', 'max:250'],
            'service' => ['nullable', Rule::in(array_keys(Catalog::SERVICES))],
            'payment_method' => ['nullable', 'string', 'starts_with:pm_'],
            'confirm' => ['accepted'],
            ...$this->budgetRules(),
        ], ['confirm.accepted' => 'Tick the box to confirm the charge.']);

        $amount = $this->toCents($data['amount']);
        $budget = $this->budgetFromRequest($request, $amount, $client);
        $charged = $budget['charge'] ?? $amount;

        return $this->stripeAction(
            fn () => $stripe->chargeNow($client, $amount, $data['description'], $data['service'] ?? null, $data['payment_method'] ?? null, $budget, $data['statement_descriptor'] ?? null),
            'Charged '.number_format($charged / 100, 2).' '.strtoupper($stripe->currency()).' — invoice and receipt created.'
        );
    }

    public function makeDefault(Client $client, string $paymentMethod, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(
            fn () => $stripe->setDefaultPaymentMethod($client, $paymentMethod),
            'Default card updated — invoices and recurring plans will charge this card.'
        );
    }

    public function destroy(Client $client, string $paymentMethod, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->removePaymentMethod($client, $paymentMethod), 'Card removed.');
    }

    public function charge(Request $request, Invoice $invoice, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate(['payment_method' => ['nullable', 'string', 'starts_with:pm_']]);

        return $this->stripeAction(
            fn () => $stripe->chargeInvoice($invoice, $data['payment_method'] ?? null),
            'Card charged — invoice paid.'
        );
    }

    /** Public thank-you page the client lands on after saving a card. */
    public function saved(): Response
    {
        return Inertia::render('Cards/Saved');
    }
}
