<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Investments: land, a business share (our Agro too), shares, FDR, DPS… Money moved in is still
 * mine; what it is worth now, what it has paid back and what it made are shown per investment.
 */
class InvestmentController extends Controller
{
    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $returns = MoneyTransaction::whereNotNull('investment_id')->where('kind', 'income')->get()
            ->groupBy('investment_id')->map(fn ($list) => (int) $list->sum(fn ($t) => $t->amount - $t->fee));

        $rows = Account::where('kind', 'investment')->orderBy('archived')->orderBy('name')->get()->map(function (Account $a) use ($returns) {
            // What went in (minus what came back out). A sale above cost leaves it below zero: that is the profit.
            $invested = Ledger::balance($a);
            $current = $a->current_value ?? max(0, $invested);
            $paid = $returns[$a->id] ?? 0;

            return [
                ...$a->only('id', 'name', 'scope', 'inv_type', 'expected_rate', 'notes', 'archived'),
                'currency' => strtoupper($a->currency),
                'maturity_on' => $a->maturity_on?->toDateString(),
                'value_at' => $a->value_at?->toIso8601String(),
                'invested' => $invested,
                'current' => $current,
                'returns' => $paid,
                'gain' => $current - $invested + $paid,
            ];
        });

        return Inertia::render('Personal/Investments', [
            'investments' => $rows->values(),
            'types' => Catalog::INVESTMENT_TYPES,
            'accounts' => Account::where('kind', '!=', 'investment')->where('archived', false)->orderBy('scope', 'desc')->orderBy('name')->get()
                ->map(fn (Account $a) => [...$a->only('id', 'name', 'scope', 'last4'), 'currency' => strtoupper($a->currency), 'balance' => Ledger::balance($a)])->values(),
            'currencies' => Catalog::PERSONAL_CURRENCIES,
        ]);
    }

    /** A new investment, with the money that went into it (moved from an account). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'scope' => ['required', Rule::in(['personal', 'business'])],
            'currency' => ['required', Rule::in(Catalog::PERSONAL_CURRENCIES)],
            'from_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('currency', $request->input('currency'))->whereNot('kind', 'investment')],
            'amount' => ['nullable', 'required_with:from_account_id', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
        ], ['from_account_id.exists' => 'Pick an account in the same currency.']);

        DB::transaction(function () use ($data) {
            $investment = Account::create([
                ...$this->details($data),
                'scope' => $data['scope'], 'kind' => 'investment', 'currency' => $data['currency'], 'provider' => 'other', 'purpose' => 'savings', 'opening_balance' => 0,
            ]);
            if ($data['from_account_id'] ?? null) {
                $this->transfer($data['from_account_id'], $investment->id, $data['amount'], $data['fee'] ?? 0, $data['date'], "Invested in {$investment->name}");
            }
        });

        return back()->with('success', 'Investment added.');
    }

    public function update(Request $request, Account $investment): RedirectResponse
    {
        abort_unless($investment->kind === 'investment', 404);
        $investment->update($this->details($request->validate($this->rules())));

        return back()->with('success', 'Saved.');
    }

    /** What it is worth now (land, shares, gold, crypto); empty = what went in. */
    public function value(Request $request, Account $investment): RedirectResponse
    {
        abort_unless($investment->kind === 'investment', 404);
        $data = $request->validate(['value' => ['nullable', 'numeric', 'min:0']]);
        $investment->update(['current_value' => filled($data['value'] ?? null) ? $this->toCents($data['value']) : null, 'value_at' => now()]);

        return back()->with('success', 'Value updated.');
    }

    /** Moves money between an account and an investment (amounts as typed, in units). */
    private function transfer(int|string $from, int|string $to, mixed $amount, mixed $fee, string $date, string $note): MoneyTransaction
    {
        return Ledger::save([
            'kind' => 'transfer', 'account_id' => (int) $from, 'to_account_id' => (int) $to,
            'amount' => $this->toCents($amount), 'fee' => $this->toCents($fee ?: 0), 'description' => $note, 'occurred_on' => $date,
        ]);
    }

    /** More money in (another instalment, registration cost…), or money taken out / sold. */
    public function move(Request $request, Account $investment): RedirectResponse
    {
        abort_unless($investment->kind === 'investment', 404);
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('currency', $investment->currency)->whereNot('kind', 'investment')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'close' => ['boolean'],
        ], ['account_id.exists' => "Pick a {$investment->currency} account."]);

        DB::transaction(function () use ($data, $investment) {
            $data['direction'] === 'in'
                ? $this->transfer($data['account_id'], $investment->id, $data['amount'], $data['fee'] ?? 0, $data['date'], "Added to {$investment->name}")
                : $this->transfer($investment->id, $data['account_id'], $data['amount'], $data['fee'] ?? 0, $data['date'], "Taken out of {$investment->name}");
            // Sold / matured in full: it is worth nothing more and leaves the list.
            if ($data['close'] ?? false) {
                $investment->update(['current_value' => 0, 'value_at' => now(), 'archived' => true]);
            }
        });

        return back()->with('success', ($data['close'] ?? false) ? 'Closed — what it made stays in the list.' : 'Saved.');
    }

    /** A return it paid (FDR profit, rent from land, a business's share of profit) into an account. */
    public function return(Request $request, Account $investment): RedirectResponse
    {
        abort_unless($investment->kind === 'investment', 404);
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('currency', $investment->currency)->whereNot('kind', 'investment')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
        ]);
        Ledger::save([
            'kind' => 'income', 'account_id' => $data['account_id'], 'amount' => $this->toCents($data['amount']), 'category' => 'investment',
            'counterparty' => $investment->name, 'investment_id' => $investment->id, 'occurred_on' => $data['date'],
        ]);

        return back()->with('success', 'Return recorded.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'inv_type' => ['required', Rule::in(array_keys(Catalog::INVESTMENT_TYPES))],
            'expected_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'maturity_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function details(array $data): array
    {
        return [
            'name' => $data['name'], 'inv_type' => $data['inv_type'], 'expected_rate' => $data['expected_rate'] ?? null,
            'maturity_on' => $data['maturity_on'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
    }
}
