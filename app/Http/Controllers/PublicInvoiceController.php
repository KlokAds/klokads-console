<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\StripeService;
use App\Support\InvoiceDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
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

        $doc = InvoiceDocument::for($invoice);
        $secret = null;
        $payError = null;

        if ($invoice->status === 'open') {
            try {
                $secret = $stripe->paymentSecret($invoice);
            } catch (Throwable $e) {
                $payError = 'Online payment is temporarily unavailable. Please try again shortly.';
            }
        }

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
                'client_secret' => $secret,
                'error' => $payError,
            ],
            'justPaid' => $request->boolean('paid') && $invoice->status === 'paid',
        ]);
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
