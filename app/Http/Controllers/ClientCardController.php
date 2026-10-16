<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\StripeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
