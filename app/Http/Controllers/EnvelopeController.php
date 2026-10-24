<?php

namespace App\Http\Controllers;

use App\Models\Envelope;
use App\Models\EnvelopeMonth;
use App\Models\EnvelopeSpend;
use App\Services\Envelopes;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Monthly envelopes: set them up, hand out the month's money, spend it down by purpose. */
class EnvelopeController extends Controller
{
    public function __construct(private Envelopes $envelopes) {}

    public function store(Request $request): RedirectResponse
    {
        Envelope::create($this->validated($request) + ['sort' => (int) Envelope::max('sort') + 1]);

        return back()->with('success', 'Envelope added.');
    }

    public function update(Request $request, Envelope $envelope): RedirectResponse
    {
        $envelope->update($this->validated($request));

        return back()->with('success', 'Envelope saved.');
    }

    public function destroy(Envelope $envelope): RedirectResponse
    {
        $envelope->update(['active' => false]);

        return back()->with('success', "{$envelope->name} stopped — its history is kept.");
    }

    /** Hand out this month's money: the source account is debited now. */
    public function assign(Request $request, Envelope $envelope): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'personal')->where('currency', $envelope->currency)],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
        ], ['account_id.exists' => "Pick a {$envelope->currency} account."]);

        abort_if($envelope->months()->where('month', $data['month'])->exists(), 422, 'Already handed out for that month.');
        $this->envelopes->assign($envelope, $data['month'], $this->toCents($data['amount']), $data['account_id'] ?? null, $data['date'], $this->toCents($data['fee'] ?? 0));

        return back()->with('success', "{$envelope->name}: handed out.");
    }

    public function unassign(EnvelopeMonth $month): RedirectResponse
    {
        $this->envelopes->unassign($month);

        return back()->with('success', 'Hand-out undone — its debit is removed.');
    }

    public function spend(Request $request, Envelope $envelope): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'spent_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $envelope->spends()->create([...$data, 'amount' => $this->toCents($data['amount']), 'month' => substr($data['spent_on'], 0, 7)]);
        // A new purpose is remembered for next time.
        if (! in_array($data['purpose'], $envelope->purposes ?? [], true)) {
            $envelope->update(['purposes' => [...($envelope->purposes ?? []), $data['purpose']]]);
        }

        return back()->with('success', 'Saved.');
    }

    public function destroySpend(EnvelopeSpend $spend): RedirectResponse
    {
        $spend->delete();

        return back()->with('success', 'Removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'currency' => ['required', Rule::in(Catalog::PERSONAL_CURRENCIES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'personal')->where('currency', $request->input('currency'))],
            'purposes' => ['array', 'max:30'],
            'purposes.*' => ['string', 'max:60'],
            'day' => ['required', 'integer', 'between:1,28'],
            'active' => ['boolean'],
        ], ['account_id.exists' => 'Pick an account in the same currency.']);

        return [...$data, 'amount' => $this->toCents($data['amount']), 'purposes' => array_values(array_unique(array_filter(array_map('trim', $data['purposes'] ?? []))))];
    }
}
