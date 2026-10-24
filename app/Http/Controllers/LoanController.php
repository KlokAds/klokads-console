<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dena-paona. Lending or borrowing moves money in or out of an account (so balances
 * stay right) under loan categories, which month totals treat as neither spending nor income.
 */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $loans = Loan::with('payments', 'account:id,name')->latest('started_on')->latest('id')->get();

        $rows = $loans->map(fn (Loan $l) => [
            ...$l->only('id', 'direction', 'person', 'phone', 'currency', 'principal', 'fee', 'account_id', 'note'),
            'started_on' => $l->started_on->toDateString(),
            'due_on' => $l->due_on?->toDateString(),
            'account' => $l->account?->name,
            'repaid' => $l->repaid(),
            'outstanding' => $out = $l->outstanding(),
            'overdue' => $out > 0 && $l->due_on && $l->due_on->lt(today()),
            'payments' => $l->payments->map(fn (LoanPayment $p) => $p->only('id', 'amount', 'note') + ['paid_on' => $p->paid_on->toDateString()]),
        ]);

        return Inertia::render('Personal/Loans', [
            'loans' => $rows,
            'totals' => collect(Catalog::PERSONAL_CURRENCIES)->mapWithKeys(fn ($c) => [$c => [
                'to_receive' => (int) $rows->where('direction', 'lent')->where('currency', $c)->sum('outstanding'),
                'to_pay' => (int) $rows->where('direction', 'borrowed')->where('currency', $c)->sum('outstanding'),
            ]]),
            'accounts' => Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get(['id', 'name', 'currency']),
            'people' => $loans->pluck('person')->unique()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $loan = Loan::create($data);
            $loan->update(['money_transaction_id' => $this->move($loan, $loan->principal, $loan->account_id, $loan->started_on->toDateString(), true, $loan->fee)?->id]);
        });

        return back()->with('success', $data['direction'] === 'lent' ? "Saved — {$data['person']} owes you." : "Saved — you owe {$data['person']}.");
    }

    public function update(Request $request, Loan $loan): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($loan, $data) {
            $loan->update($data);
            // Keep the money entry in step with the edited amount / account / date.
            if ($tx = MoneyTransaction::find($loan->money_transaction_id)) {
                Ledger::delete($tx);
            }
            $loan->update(['money_transaction_id' => $this->move($loan, $loan->principal, $loan->account_id, $loan->started_on->toDateString(), true, $loan->fee)?->id]);
        });

        return back()->with('success', 'Updated.');
    }

    public function destroy(Loan $loan): RedirectResponse
    {
        DB::transaction(function () use ($loan) {
            $ids = $loan->payments()->pluck('money_transaction_id')->push($loan->money_transaction_id)->filter();
            MoneyTransaction::whereKey($ids)->get()->each(fn ($tx) => Ledger::delete($tx));
            $loan->delete();
        });

        return back()->with('success', 'Removed, with its money entries.');
    }

    /** A repayment: they paid me back, or I paid them back. */
    public function pay(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'personal')->where('currency', $loan->currency)],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['account_id.exists' => "Pick a {$loan->currency} account."]);

        DB::transaction(function () use ($loan, $data) {
            $payment = $loan->payments()->create([...$data, 'amount' => $this->toCents($data['amount'])]);
            $payment->update(['money_transaction_id' => $this->move($loan, $payment->amount, $payment->account_id, $payment->paid_on->toDateString(), false)?->id]);
        });

        $left = $loan->fresh()->outstanding();

        return back()->with('success', $left ? 'Saved — '.$loan->currency.' '.number_format($left / 100, 2).' left.' : 'Settled in full. 🎉');
    }

    public function destroyPayment(LoanPayment $payment): RedirectResponse
    {
        DB::transaction(function () use ($payment) {
            if ($tx = MoneyTransaction::find($payment->money_transaction_id)) {
                Ledger::delete($tx);
            }
            $payment->delete();
        });

        return back()->with('success', 'Repayment removed.');
    }

    /**
     * Record the money moving in/out of the chosen account (if any). If the bank / wallet
     * SMS for it already came in as an automatic entry, that entry becomes this one.
     */
    private function move(Loan $loan, int $amount, ?int $accountId, string $date, bool $opening, int $fee = 0): ?MoneyTransaction
    {
        if (! $accountId) {
            return null; // e.g. cash that isn't tracked
        }

        // Money leaves me when I lend or repay; it arrives when I borrow or get repaid.
        $out = ($loan->direction === 'lent') === $opening;
        $category = match (true) {
            $opening && $loan->direction === 'lent' => 'loan_given',
            $opening => 'loan_taken',
            $loan->direction === 'lent' => 'loan_repaid_to_me',
            default => 'loan_repaid_by_me',
        };

        $entry = [
            'kind' => $out ? 'expense' : 'income',
            'account_id' => $accountId,
            'amount' => $amount,
            'fee' => $out ? $fee : 0,
            'category' => $category,
            'counterparty' => $loan->person,
            'description' => Catalog::LOAN_CATEGORIES[$category],
            'occurred_on' => $date,
        ];

        $auto = MoneyTransaction::where('account_id', $accountId)->where('kind', $entry['kind'])->where('amount', $amount)
            ->where('description', 'like', 'Auto ·%')->whereNotIn('category', array_keys(Catalog::LOAN_CATEGORIES))
            ->whereBetween('occurred_on', [CarbonImmutable::parse($date)->subDays(2)->toDateString(), CarbonImmutable::parse($date)->addDays(2)->toDateString().' 23:59:59'])
            ->latest('id')->first();
        if ($auto) {
            $auto->update(['category' => $category, 'counterparty' => $loan->person, 'description' => $entry['description'].' · '.$auto->description, 'fee' => $auto->fee ?: $entry['fee']]);

            return $auto;
        }

        return Ledger::save($entry);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['lent', 'borrowed'])],
            'person' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'currency' => ['required', Rule::in(Catalog::PERSONAL_CURRENCIES)],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('scope', 'personal')->where('currency', $request->input('currency'))],
            'started_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['account_id.exists' => 'Pick an account in the same currency.']);

        return [...$data, 'principal' => $this->toCents($data['principal']), 'fee' => $this->toCents($data['fee'] ?? 0)];
    }
}
