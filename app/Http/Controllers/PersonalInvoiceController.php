<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Models\PersonalRecurring;
use App\Models\Setting;
use App\Services\PersonalInvoicing;
use App\Support\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** Personal invoices: clients, invoices, monthly invoices, payment details, the client's pay page. */
class PersonalInvoiceController extends Controller
{
    public function __construct(private PersonalInvoicing $invoicing) {}

    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $invoices = PersonalInvoice::with('client', 'payments')->latest('issued_on')->latest('id')->get();

        return Inertia::render('Personal/Invoices', [
            'invoices' => $invoices->map(fn (PersonalInvoice $i) => [
                ...$i->only('id', 'number', 'personal_client_id', 'currency', 'items', 'total', 'paid', 'status', 'note', 'reported'),
                'client' => $i->client->only('id', 'name', 'company', 'email', 'phone'),
                'due' => $i->due(),
                'issued_on' => $i->issued_on->toDateString(),
                'due_on' => $i->due_on?->toDateString(),
                'paid_on' => $i->paid_on?->toDateString(),
                'sent_at' => $i->sent_at?->toIso8601String(),
                'overdue' => $i->due() > 0 && $i->status !== 'void' && $i->due_on && $i->due_on->lt(today()),
                'url' => $i->publicUrl(),
                'payments' => $i->payments->map(fn ($p) => $p->only('id', 'amount', 'method', 'reference', 'source') + ['paid_on' => $p->paid_on->toDateString()]),
            ]),
            'clients' => PersonalClient::orderBy('name')->get(),
            'recurring' => PersonalRecurring::with('client:id,name,company')->orderBy('day')->get()
                ->map(fn ($r) => [...$r->only('id', 'personal_client_id', 'currency', 'items', 'day', 'due_days', 'auto_send', 'active', 'note'),
                    'client' => $r->client, 'next_on' => $r->next_on->toDateString(), 'total' => PersonalInvoicing::total($r->items)]),
            'profile' => PersonalInvoicing::profile(),
            'methods' => Catalog::options(Catalog::PAY_METHODS),
            'accounts' => Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get(['id', 'name', 'currency', 'provider']),
            'totals' => [
                'due' => (int) $invoices->whereIn('status', ['unpaid', 'partial'])->sum(fn ($i) => $i->due()),
                'overdue' => (int) $invoices->whereIn('status', ['unpaid', 'partial'])->filter(fn ($i) => $i->due_on?->lt(today()))->sum(fn ($i) => $i->due()),
                'month_paid' => (int) PersonalInvoicePayment::where('paid_on', '>=', today()->startOfMonth()->toDateString())->sum('amount'),
                'on_hold' => $invoices->whereNotNull('reported')->whereIn('status', ['unpaid', 'partial'])->count(),
            ],
        ]);
    }

    // ---------------------------------------------------------------- invoices

    public function store(Request $request): RedirectResponse
    {
        $data = $this->invoiceData($request);
        $invoice = $this->invoicing->create(PersonalClient::findOrFail($data['personal_client_id']), $data['items'],
            CarbonImmutable::parse($data['issued_on']), isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null, $data['note'] ?? null);

        if ($request->boolean('send')) {
            return $this->trySend($invoice, "{$invoice->number} created and emailed.");
        }

        return back()->with('success', "{$invoice->number} created.");
    }

    public function update(Request $request, PersonalInvoice $invoice): RedirectResponse
    {
        $data = $this->invoiceData($request);
        $invoice->update([...collect($data)->except('send')->all(), 'total' => PersonalInvoicing::total($data['items'])]);
        $this->invoicing->refresh($invoice->fresh());

        return back()->with('success', 'Invoice updated.');
    }

    public function destroy(PersonalInvoice $invoice): RedirectResponse
    {
        $invoice->payments->each(fn ($p) => $this->invoicing->removePayment($p));
        $invoice->delete();

        return back()->with('success', 'Invoice deleted.');
    }

    public function void(PersonalInvoice $invoice): RedirectResponse
    {
        $invoice->update(['status' => $invoice->status === 'void' ? 'unpaid' : 'void', 'reported' => null]);
        if ($invoice->status !== 'void') {
            $this->invoicing->refresh($invoice);
        }

        return back()->with('success', $invoice->status === 'void' ? 'Marked void.' : 'Restored.');
    }

    public function send(PersonalInvoice $invoice): RedirectResponse
    {
        return $this->trySend($invoice, "Emailed to {$invoice->client->email}.");
    }

    public function pay(Request $request, PersonalInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::in(array_keys(Catalog::PAY_METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'personal')->where('currency', $invoice->currency)],
        ], ['account_id.exists' => "Pick a {$invoice->currency} account."]);

        $this->invoicing->recordPayment($invoice, $this->toCents($data['amount']), $data['paid_on'], $data['method'], $data['reference'] ?? null, $data['account_id'] ?? null);

        return back()->with('success', $invoice->fresh()->status === 'paid' ? "{$invoice->number} is paid." : 'Payment saved.');
    }

    public function destroyPayment(PersonalInvoicePayment $payment): RedirectResponse
    {
        $this->invoicing->removePayment($payment);

        return back()->with('success', 'Payment removed.');
    }

    /** "Not received" — clear what the client reported. */
    public function dismissReport(PersonalInvoice $invoice): RedirectResponse
    {
        $invoice->update(['reported' => null]);

        return back()->with('success', 'Cleared.');
    }

    public function pdf(PersonalInvoice $invoice): HttpResponse
    {
        return $this->invoicing->pdf($invoice)->stream("{$invoice->number}.pdf");
    }

    // ---------------------------------------------------------------- clients

    public function storeClient(Request $request): RedirectResponse
    {
        PersonalClient::create($this->clientData($request));

        return back()->with('success', 'Client added.');
    }

    public function updateClient(Request $request, PersonalClient $client): RedirectResponse
    {
        $client->update($this->clientData($request));

        return back()->with('success', 'Client updated.');
    }

    public function destroyClient(PersonalClient $client): RedirectResponse
    {
        if ($client->invoices()->exists()) {
            return back()->with('error', 'This client has invoices — delete those first.');
        }
        $client->delete();

        return back()->with('success', 'Client removed.');
    }

    // ---------------------------------------------------------------- monthly

    public function storeRecurring(Request $request): RedirectResponse
    {
        $data = $this->recurringData($request);
        $today = CarbonImmutable::today();
        $first = $today->setDay(min($data['day'], $today->daysInMonth));
        PersonalRecurring::create($data + ['next_on' => ($first->lt($today) ? PersonalInvoicing::nextOn($today, $data['day']) : $first)->toDateString()]);

        return back()->with('success', 'Monthly invoice set up.');
    }

    public function updateRecurring(Request $request, PersonalRecurring $recurring): RedirectResponse
    {
        $recurring->update($this->recurringData($request));

        return back()->with('success', 'Monthly invoice updated.');
    }

    public function destroyRecurring(PersonalRecurring $recurring): RedirectResponse
    {
        $recurring->delete();

        return back()->with('success', 'Monthly invoice stopped — past invoices are kept.');
    }

    // ---------------------------------------------------------------- my details

    public function saveProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'methods' => ['array', 'max:10'],
            'methods.*.type' => ['required', Rule::in(array_keys(Catalog::PAY_METHODS))],
            'methods.*.label' => ['nullable', 'string', 'max:60'],
            'methods.*.name' => ['nullable', 'string', 'max:120'],
            'methods.*.number' => ['required', 'string', 'max:80'],
            'methods.*.extra' => ['nullable', 'string', 'max:160'],
            'qr' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:600'],
            'remove_qr' => ['boolean'],
        ], ['methods.*.number.required' => 'Add the account / wallet number.']);

        foreach (['name', 'email', 'phone', 'address'] as $key) {
            Setting::put("personal_{$key}", $data[$key] ?? null);
        }
        Setting::put('personal_pay_methods', json_encode(array_values($data['methods'] ?? [])));

        if ($request->hasFile('qr')) {
            $file = $request->file('qr');
            Setting::put('personal_qr', 'data:'.$file->getMimeType().';base64,'.base64_encode($file->get()));
        } elseif ($request->boolean('remove_qr')) {
            Setting::put('personal_qr', null);
        }

        return back()->with('success', 'Your invoice details are saved.');
    }

    // ---------------------------------------------------------------- client-facing

    public function public(string $token): Response
    {
        $invoice = PersonalInvoice::with('client')->where('public_token', $token)->firstOrFail();

        return Inertia::render('Public/PersonalInvoice', [
            'invoice' => [
                ...$invoice->only('number', 'currency', 'items', 'total', 'paid', 'status', 'note'),
                'due' => $invoice->due(),
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'paid_on' => $invoice->paid_on?->toDateString(),
                'client' => $invoice->client->only('name', 'company'),
                'reported' => (bool) $invoice->reported,
            ],
            'me' => PersonalInvoicing::profile(),
            'methods' => Catalog::PAY_METHODS,
            'token' => $token,
        ]);
    }

    public function publicPdf(string $token): HttpResponse
    {
        $invoice = PersonalInvoice::where('public_token', $token)->firstOrFail();

        return $this->invoicing->pdf($invoice)->download("{$invoice->number}.pdf");
    }

    /** The client says they paid — kept on hold until confirmed. */
    public function report(Request $request, string $token): RedirectResponse
    {
        $invoice = PersonalInvoice::with('client')->where('public_token', $token)->firstOrFail();
        abort_if(in_array($invoice->status, ['paid', 'void'], true), 404);

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Catalog::PAY_METHODS))],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.($invoice->due() / 100)],
            'reference' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'string', 'max:40'],
        ]);
        $this->invoicing->report($invoice, [...$data, 'amount' => $this->toCents($data['amount'])]);

        return back()->with('success', 'Thank you! Your payment will be confirmed shortly.');
    }

    // ---------------------------------------------------------------- helpers

    private function trySend(PersonalInvoice $invoice, string $ok): RedirectResponse
    {
        try {
            $this->invoicing->send($invoice->loadMissing('client'));
        } catch (Throwable $e) {
            return back()->with('error', 'Saved, but not emailed: '.$e->getMessage());
        }

        return back()->with('success', $ok);
    }

    /** @return array<string, mixed> */
    private function invoiceData(Request $request): array
    {
        $data = $request->validate([
            'personal_client_id' => ['required', 'exists:personal_clients,id'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'issued_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'note' => ['nullable', 'string', 'max:1000'],
            'send' => ['boolean'],
        ]);

        return [...$data, 'items' => $this->items($data['items'])];
    }

    /** @return array<string, mixed> */
    private function recurringData(Request $request): array
    {
        $data = $request->validate([
            'personal_client_id' => ['required', 'exists:personal_clients,id'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'day' => ['required', 'integer', 'between:1,28'],
            'due_days' => ['required', 'integer', 'between:0,60'],
            'auto_send' => ['boolean'],
            'active' => ['boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return [...$data, 'items' => $this->items($data['items'])];
    }

    /** Rates come in as taka; stored in poisha (cents). */
    private function items(array $items): array
    {
        return collect($items)->map(fn ($i) => ['description' => $i['description'], 'qty' => (float) $i['qty'], 'rate' => $this->toCents($i['rate'])])->values()->all();
    }

    /** @return array<string, mixed> */
    private function clientData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
