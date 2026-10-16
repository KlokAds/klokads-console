<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\CashflowReport;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request, StripeService $stripe): Response
    {
        $clients = Client::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('company', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->withSum(['transactions as revenue' => fn ($q) => $q->where('reporting_category', 'charge')], 'amount')
            ->withSum(['invoices as outstanding' => fn ($q) => $q->where('status', 'open')], 'amount_remaining')
            ->withSum('expenses as spend', 'amount')
            ->withCount(['plans as active_plans' => fn ($q) => $q->whereIn('status', ['active', 'trialing', 'past_due'])])
            ->orderBy('name')
            ->get();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => $request->only('search'),
            'currency' => $stripe->currency(),
        ]);
    }

    public function show(Client $client, StripeService $stripe): Response
    {
        $row = collect(CashflowReport::forPeriod('all')->clients(PHP_INT_MAX))->firstWhere('id', $client->id);

        return Inertia::render('Clients/Show', [
            'client' => $client,
            'currency' => $stripe->currency(),
            'totals' => $row ?? ['revenue' => 0, 'fees' => 0, 'spend' => 0, 'profit' => 0, 'outstanding' => 0],
            'invoices' => $client->invoices()->latest('issued_at')->get(),
            'plans' => $client->plans()->latest()->get(),
            'paymentLinks' => $client->paymentLinks()->latest()->get(),
            'expenses' => $client->expenses()->latest('spent_on')->limit(50)->get(),
            'serviceLabels' => Catalog::SERVICES,
            'categoryLabels' => Catalog::EXPENSE_CATEGORIES,
        ]);
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $client = Client::create($this->validated($request));

        if (! StripeService::configured()) {
            return back()->with('success', 'Client added. Connect Stripe in Settings to bill them.');
        }

        return $this->stripeAction(fn () => $stripe->ensureCustomer($client), 'Client added and created in Stripe.');
    }

    public function update(Request $request, Client $client, StripeService $stripe): RedirectResponse
    {
        $client->update($this->validated($request));

        if (! StripeService::configured()) {
            return back()->with('success', 'Client updated.');
        }

        return $this->stripeAction(fn () => $stripe->updateCustomer($client), 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $client->delete();

        return redirect()->route('clients.index')->with('success', 'Client removed (Stripe customer kept).');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
