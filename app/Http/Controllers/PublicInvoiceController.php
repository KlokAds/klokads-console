<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\StripeService;
use App\Support\InvoiceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\CardException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The client-facing invoice / receipt page on our own domain: view, download the
 * PDF in the chosen template, and pay by card in Stripe's secure card box.
 */
class PublicInvoiceController extends Controller
{
    public function show(Request $request, string $token, StripeService $stripe): Response
    {
        $invoice = $this->find($token);

        // Coming back from a card payment: pull the new status straight away.
        if ($request->boolean('paid') && $invoice->status === 'open') {
            try {
                $invoice = $stripe->refreshInvoice($invoice);
            } catch (Throwable) {
                // The page still loads; auto-sync catches up later.
            }
        }

        $secret = null;
        $payError = null;
        $cards = [];

        if ($invoice->status === 'open') {
            try {
                $secret = $stripe->paymentSecret($invoice);
                // Cards the client saved with us: paid with one tap (their tap is their OK).
                if (! $invoice->stripe_invoice_id && $invoice->client) {
                    $cards = collect($stripe->paymentMethods($invoice->client))->where('type', 'card')
                        ->map(fn ($c) => collect($c)->only('id', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default')->all())->values()->all();
                }
            } catch (Throwable $e) {
                $payError = 'Online payment is temporarily unavailable. Please try again shortly.';
            }
            $invoice->refresh(); // the pay box may have found it paid already
        }
        $doc = InvoiceDocument::for($invoice);

        return Inertia::render('Public/Invoice', [
            'doc' => collect($doc->data)->except(['client', 'pay_url'])->all() + [
                'client' => [
                    'name' => $doc->data['client']?->name,
                    'company' => $doc->data['client']?->company,
                    'email' => $doc->data['client']?->email,
                ],
            ],
            'type' => $doc->type,
            'token' => $token,
            'payment' => [
                'publishable_key' => Setting::get('stripe_publishable'),
                'client_secret' => $invoice->status === 'open' ? $secret : null,
                'error' => $payError,
                'cards' => $cards,
            ],
            'justPaid' => $request->boolean('paid') && $invoice->status === 'paid',
        ]);
    }

    /** The client pays with a card they saved: charged now, or a 3-D Secure check first. */
    public function paySaved(Request $request, string $token, StripeService $stripe): JsonResponse
    {
        $invoice = $this->find($token);
        $data = $request->validate(['payment_method' => ['required', 'string', 'starts_with:pm_']]);
        if ($invoice->status !== 'open' || $invoice->stripe_invoice_id) {
            return response()->json(['error' => 'This invoice cannot be paid here any more.'], 422);
        }

        try {
            return response()->json($stripe->payWithSavedCard($invoice, $data['payment_method']));
        } catch (Throwable $e) {
            return response()->json(['error' => $e instanceof CardException ? $e->getMessage() : 'The card payment did not go through. Try another card.'], 422);
        }
    }

    public function pdf(Request $request, string $token): HttpResponse
    {
        $doc = InvoiceDocument::for($this->find($token), null, $request->input('type'));

        return $doc->pdf()->download($doc->filename());
    }

    private function find(string $token): Invoice
    {
        abort_unless(strlen($token) === 40, 404);

        return Invoice::withoutGlobalScope('stripe_mode')->with('client')->where('public_token', $token)->firstOrFail();
    }
}
