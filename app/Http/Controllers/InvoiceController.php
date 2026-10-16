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

class InvoiceController extends Controller
{
    public function index(Request $request, StripeService $stripe): Response
    {
        $invoices = Invoice::with('client:id,name,company')
            ->when($request->status, fn ($q, $s) => $s === 'overdue'
                ? $q->where('status', 'open')->where('due_at', '<', now())
                : $q->where('status', $s))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->type === 'recurring', fn ($q) => $q->whereNotNull('stripe_subscription_id'))
            ->when($request->type === 'one_off', fn ($q) => $q->whereNull('stripe_subscription_id'))
            ->latest('issued_at')
            ->paginate(25)->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only('status', 'client_id', 'type'),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company']),
            'serviceLabels' => Catalog::SERVICES,
            'currency' => $stripe->currency(),
            'totals' => [
                'open' => (int) Invoice::where('status', 'open')->sum('amount_remaining'),
                'paid' => (int) Invoice::where('status', 'paid')->sum('amount_paid'),
                'overdue' => (int) Invoice::where('status', 'open')->where('due_at', '<', now())->sum('amount_remaining'),
            ],
        ]);
    }

    public function create(Request $request, StripeService $stripe): Response
    {
        return Inertia::render('Invoices/Create', [
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company', 'email']),
            'services' => Catalog::options(Catalog::SERVICES),
            'currency' => $stripe->currency(),
            'clientId' => $request->integer('client_id') ?: null,
        ]);
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'service' => ['nullable', Rule::in(array_keys(Catalog::SERVICES))],
            'memo' => ['nullable', 'string', 'max:500'],
            'days_until_due' => ['required', 'integer', 'min:0', 'max:365'],
            'send' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:250'],
            'items.*.amount' => ['required', 'numeric', 'min:0.5'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $items = collect($data['items'])->map(fn ($i) => [
            'description' => $i['description'],
            'amount' => $this->toCents($i['amount']),
            'quantity' => (int) $i['quantity'],
        ])->all();

        return $this->stripeAction(
            function () use ($stripe, $data, $items) {
                $stripe->createInvoice(Client::findOrFail($data['client_id']), $items, $data);

                return redirect()->route('invoices.index');
            },
            $request->boolean('send') ? 'Invoice created and emailed to the client.' : 'Invoice created — copy the link to share it.'
        );
    }

    public function send(Invoice $invoice, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->sendInvoice($invoice), 'Invoice emailed to the client.');
    }

    public function void(Invoice $invoice, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->voidInvoice($invoice), 'Invoice voided.');
    }

    public function markPaid(Invoice $invoice, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->markInvoicePaid($invoice), 'Marked as paid outside Stripe.');
    }

    public function refresh(Invoice $invoice, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->refreshInvoice($invoice), 'Invoice refreshed from Stripe.');
    }
}
