<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\Payout;
use Stripe\Subscription;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Keeps the dashboard live. Events to enable in Stripe → Developers → Webhooks:
 * invoice.*, customer.subscription.*, charge.succeeded, charge.refunded, payout.*, balance.available
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeService $stripe): Response
    {
        $secret = Setting::get('stripe_webhook_secret');
        if (! $secret) {
            return response('Webhook secret not configured', 400);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        $object = $event->data->object;
        $type = $event->type;

        if (str_starts_with($type, 'invoice.') && $object instanceof Invoice) {
            $stripe->upsertInvoice($object);
        } elseif (str_starts_with($type, 'customer.subscription.') && $object instanceof Subscription) {
            $stripe->upsertSubscription($object);
        } elseif (str_starts_with($type, 'payout.') && $object instanceof Payout) {
            $stripe->upsertPayout($object);
        }

        // Money moved: pull the latest balance transactions (fees, nets) and refresh the balance.
        if (str_starts_with($type, 'charge.') || str_starts_with($type, 'payout.') || $type === 'balance.available' || $type === 'invoice.paid') {
            $stripe->syncTransactions(now()->subDays(3));
            StripeService::forgetBalance();
        }

        return response('ok');
    }
}
