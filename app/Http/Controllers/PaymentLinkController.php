<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaymentLink;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentLinkController extends Controller
{
    public function index(StripeService $stripe): Response
    {
        return Inertia::render('PaymentLinks/Index', [
            'links' => PaymentLink::with('client:id,name,company')->latest()->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company', 'commission_rate']),
            'services' => Catalog::options(Catalog::SERVICES),
            'serviceLabels' => Catalog::SERVICES,
            'currency' => $stripe->currency(),
        ]);
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'description' => ['required', 'string', 'max:250'],
            'service' => ['nullable', Rule::in(array_keys(Catalog::SERVICES))],
            'amount' => ['required', 'numeric', 'min:0.5'],
            ...$this->budgetRules(),
        ]);
        $data['amount'] = $this->toCents($data['amount']);
        $client = filled($data['client_id'] ?? null) ? Client::find($data['client_id']) : null;
        $data['budget'] = $this->budgetFromRequest($request, $data['amount'], $client);

        return $this->stripeAction(
            fn () => $stripe->createPaymentLink($data, $client),
            'Payment link created — copy it and send it to your client.'
        );
    }

    public function toggle(PaymentLink $paymentLink, StripeService $stripe): RedirectResponse
    {
        $active = ! $paymentLink->active;

        return $this->stripeAction(
            fn () => $stripe->setPaymentLinkActive($paymentLink, $active),
            $active ? 'Payment link re-activated.' : 'Payment link deactivated.'
        );
    }
}
