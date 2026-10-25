<?php

namespace App\Http\Controllers;

use App\Models\ChargeRequest;
use App\Services\PrepaidBalance;
use App\Support\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A drafted top-up: look, change the amount or text if needed, then approve — only then the card is charged. */
class ChargeRequestController extends Controller
{
    public function show(ChargeRequest $charge, PrepaidBalance $prepaid): Response
    {
        $client = $charge->client;

        return Inertia::render('Charges/Show', [
            'charge' => [
                ...$charge->only('id', 'kind', 'amount', 'suggested', 'currency', 'description', 'status', 'error', 'reason', 'invoice_id'),
                'created_at' => $charge->created_at->toIso8601String(),
                'decided_at' => $charge->decided_at?->toIso8601String(),
                'invoice_number' => $charge->invoice?->number,
            ],
            'client' => $client->only('id', 'name', 'company', 'email'),
            'now' => $prepaid->status($client),
            'pricing' => Pricing::forClient($client)->toArray(),
        ]);
    }

    public function approve(Request $request, ChargeRequest $charge, PrepaidBalance $prepaid): RedirectResponse
    {
        abort_unless($charge->status === 'pending', 409, 'This top-up was already handled.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:10', 'max:100000'],
            'description' => ['required', 'string', 'max:120'],
        ]);
        $charge->update(['amount' => $this->toCents($data['amount']), 'description' => $data['description']]);

        $invoice = $prepaid->topUpIfLow($charge->client->fresh(), $charge->amount, $charge->description);
        if (! $invoice) {
            $charge->update(['error' => $charge->client->fresh()->spend_error ?: 'The charge did not go through.']);

            return back()->with('error', $charge->error.' — it is still waiting; fix it and approve again, or decline.');
        }

        $charge->update(['status' => 'charged', 'invoice_id' => $invoice->id, 'error' => null, 'decided_at' => now()]);

        return back()->with('success', "Charged — invoice {$invoice->number}, and the balance is topped up.");
    }

    public function decline(ChargeRequest $charge): RedirectResponse
    {
        abort_unless($charge->status === 'pending', 409, 'This top-up was already handled.');
        $charge->update(['status' => 'declined', 'decided_at' => now()]);

        return back()->with('success', 'Declined — nothing was charged. A new one is drafted if the balance is still low tomorrow.');
    }
}
