<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A client's saved cards: add (Stripe-hosted page), make default, remove, charge an invoice.
 */
class ClientCardController extends Controller
{
    /** Create a Stripe page link the client opens to save a card. */
    public function link(Client $client, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(function () use ($client, $stripe) {
            $url = $stripe->cardSetupLink($client, route('cards.saved'));
            session()->flash('card_link', $url);
        }, 'Card link ready — send it to the client (valid for 24 hours).');
    }

    /** Admin enters the card on Stripe's page, then comes back to the client page. */
    public function enter(Client $client, StripeService $stripe): SymfonyResponse
    {
        try {
            $url = $stripe->cardSetupLink($client, route('clients.show', ['client' => $client, 'card' => 'added']));
        } catch (ApiErrorException|RuntimeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return Inertia::location($url);
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
        ], ['confirm.accepted' => 'Tick the box to confirm the charge.']);

        $amount = $this->toCents($data['amount']);

        return $this->stripeAction(
            fn () => $stripe->chargeNow($client, $amount, $data['description'], $data['service'] ?? null, $data['payment_method'] ?? null),
            'Charged '.number_format($amount / 100, 2).' '.strtoupper($stripe->currency()).' — invoice and receipt created.'
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
