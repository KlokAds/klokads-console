<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\RecurringPlan;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecurringPlanController extends Controller
{
    public function index(StripeService $stripe): Response
    {
        $plans = RecurringPlan::with('client:id,name,company')->latest()->get();

        return Inertia::render('Recurring/Index', [
            'plans' => $plans,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company']),
            'services' => Catalog::options(Catalog::SERVICES),
            'serviceLabels' => Catalog::SERVICES,
            'currency' => $stripe->currency(),
            'mrr' => (int) round($plans->whereIn('status', ['active', 'trialing', 'past_due'])->sum(fn (RecurringPlan $p) => $p->monthlyAmount())),
        ]);
    }

    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'description' => ['required', 'string', 'max:250'],
            'service' => ['nullable', Rule::in(array_keys(Catalog::SERVICES))],
            'amount' => ['required', 'numeric', 'min:0.5'],
            'interval' => ['required', Rule::in(['week', 'month', 'year'])],
            'interval_count' => ['required', 'integer', 'min:1', 'max:12'],
            'collection' => ['required', Rule::in(['auto', 'invoice'])],
            'days_until_due' => ['nullable', 'integer', 'min:0', 'max:60'],
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $data['amount'] = $this->toCents($data['amount']);

        return $this->stripeAction(
            fn () => $stripe->createRecurring(Client::findOrFail($data['client_id']), $data),
            $data['collection'] === 'auto'
                ? 'Recurring plan created. Send the first-invoice link — once the client pays by card, every next cycle is charged automatically.'
                : 'Recurring plan created. Stripe will email an invoice every cycle.'
        );
    }

    public function cancel(RecurringPlan $plan, StripeService $stripe): RedirectResponse
    {
        return $this->stripeAction(fn () => $stripe->cancelRecurring($plan), 'Recurring plan cancelled.');
    }
}
