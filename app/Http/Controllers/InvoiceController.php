<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceMailer;
use App\Services\StripeService;
use App\Support\Business;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
            'templates' => Catalog::TEMPLATES,
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
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company', 'email', 'commission_rate']),
            'services' => Catalog::options(Catalog::SERVICES),
            'templates' => Catalog::options(Catalog::TEMPLATES),
            'templateFor' => collect(Catalog::DEFAULT_TEMPLATE_FOR)->map(fn ($t, $service) => Business::templateFor($service)),
            'units' => Catalog::UNITS,
            'currency' => $stripe->currency(),
            'clientId' => $request->integer('client_id') ?: null,
            'lastDetails' => $this->lastDetailsByClient(),
        ]);
    }

    /**
     * Each client's ad account and campaign rows from their latest invoice, so a new
     * invoice starts pre-filled and only this month's numbers need typing.
     *
     * @return array<int, array{ad_account_id: ?string, template: ?string, service: ?string, campaigns: array<int, array<string, mixed>>}>
     */
    private function lastDetailsByClient(): array
    {
        return Invoice::whereNotNull('details')->latest('issued_at')->latest('id')
            ->get(['client_id', 'details', 'template', 'service'])
            ->unique('client_id')
            ->filter(fn ($i) => $i->client_id && ($i->details['campaigns'] ?? $i->details['ad_account_id'] ?? null))
            ->mapWithKeys(fn ($i) => [$i->client_id => [
                'ad_account_id' => $i->details['ad_account_id'] ?? null,
                'template' => $i->template,
                'service' => $i->service,
                'campaigns' => collect($i->details['campaigns'] ?? [])
                    ->reject(fn ($c) => ($c['amount'] ?? 0) < 0)
                    ->map(fn ($c) => ['name' => $c['name'], 'detail' => $c['detail'] ?? '', 'unit' => $c['unit'] ?? 'Impressions'])
                    ->values()->all(),
            ]])->all();
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'service' => ['nullable', Rule::in(array_keys(Catalog::SERVICES))],
            'memo' => ['nullable', 'string', 'max:500'],
            'days_until_due' => ['required', 'integer', 'min:0', 'max:365'],
            'send' => ['boolean'],
            ...$this->budgetRules(),
            'budget_amount' => ['required_if:billing,budget', 'nullable', 'numeric', 'min:1'],
            'items' => ['exclude_if:billing,budget', 'required', 'array', 'min:1'],
            'items.*.description' => ['exclude_if:billing,budget', 'required', 'string', 'max:250'],
            'items.*.amount' => ['exclude_if:billing,budget', 'required', 'numeric', 'min:0.5'],
            'items.*.quantity' => ['exclude_if:billing,budget', 'required', 'integer', 'min:1'],
            'items.*.unit' => ['exclude_if:billing,budget', 'nullable', Rule::in(Catalog::UNITS)],
            'template' => ['nullable', Rule::in(array_keys(Catalog::TEMPLATES))],
            'ad_account_id' => ['nullable', 'string', 'max:40'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'campaigns' => ['nullable', 'array', 'max:50'],
            'campaigns.*.name' => ['required', 'string', 'max:150'],
            'campaigns.*.detail' => ['nullable', 'string', 'max:150'],
            'campaigns.*.quantity' => ['nullable', 'integer', 'min:0'],
            'campaigns.*.unit' => ['nullable', Rule::in(Catalog::UNITS)],
            'campaigns.*.amount' => ['required', 'numeric', 'between:-1000000,1000000'],
        ]);

        $data['details'] = array_filter([
            'ad_account_id' => $data['ad_account_id'] ?? null,
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'campaigns' => collect($data['campaigns'] ?? [])->map(fn ($c) => [
                'name' => $c['name'],
                'detail' => $c['detail'] ?? null,
                'quantity' => isset($c['quantity']) ? (int) $c['quantity'] : null,
                'unit' => $c['unit'] ?? null,
                'amount' => $this->toCents($c['amount']),
            ])->values()->all(),
        ]) ?: null;

        $client = Client::findOrFail($data['client_id']);
        $items = collect($data['items'] ?? [])->map(fn ($i) => [
            'description' => $i['description'],
            'amount' => $this->toCents($i['amount']),
            'quantity' => (int) $i['quantity'],
            'unit' => $i['unit'] ?? null,
        ])->all();

        $data['budget'] = $this->budgetFromRequest($request, $this->toCents($data['budget_amount'] ?? 0), $client);
        $data['budget_label'] = (Catalog::SERVICES[$data['service'] ?? ''] ?? 'Ads').' budget';

        return $this->stripeAction(
            function () use ($stripe, $data, $items, $client, $request) {
                $invoice = $stripe->createInvoice($client, $items, $data);

                if ($request->boolean('send')) {
                    try {
                        app(InvoiceMailer::class)->sendInvoice($invoice);
                    } catch (Throwable $e) {
                        return redirect()->route('invoices.index')->with('error', 'Invoice created, but the email failed: '.$e->getMessage());
                    }
                }

                return redirect()->route('invoices.index');
            },
            $request->boolean('send') ? 'Invoice created and emailed to the client.' : 'Invoice created — copy the link to share it.'
        );
    }

    public function send(Invoice $invoice, InvoiceMailer $mailer): RedirectResponse
    {
        $kind = $invoice->status === 'paid' ? 'receipt' : 'invoice';

        return $this->stripeAction(
            fn () => $kind === 'receipt' ? $mailer->sendReceipt($invoice) : $mailer->sendInvoice($invoice),
            ($kind === 'receipt' ? 'Receipt' : 'Invoice')." emailed to {$invoice->client?->email}."
        );
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
