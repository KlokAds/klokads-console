<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Personal planning: monthly budgets, savings goals and monthly bills. */
class PlanningController extends Controller
{
    // ---------------------------------------------------------------- budgets

    /** Set (or clear, with 0) the monthly limits for one currency in one go. */
    public function saveBudgets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(Catalog::PERSONAL_CURRENCIES)],
            'limits' => ['array'],
            'limits.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data['limits'] ?? [] as $category => $amount) {
            if (! array_key_exists($category, Catalog::PERSONAL_CATEGORIES)) {
                continue;
            }

            $cents = $this->toCents($amount);
            $cents > 0
                ? Budget::updateOrCreate(['category' => $category, 'currency' => $data['currency']], ['amount' => $cents])
                : Budget::where(['category' => $category, 'currency' => $data['currency']])->delete();
        }

        return back()->with('success', "{$data['currency']} budgets saved.");
    }

    // ---------------------------------------------------------------- goals

    public function storeGoal(Request $request): RedirectResponse
    {
        Goal::create($this->goalData($request));

        return back()->with('success', 'Goal added.');
    }

    public function updateGoal(Request $request, Goal $goal): RedirectResponse
    {
        $goal->update($this->goalData($request));

        return back()->with('success', 'Goal updated.');
    }

    public function destroyGoal(Goal $goal): RedirectResponse
    {
        $goal->delete();

        return back()->with('success', 'Goal removed — the money stays in its account.');
    }

    // ---------------------------------------------------------------- bills

    public function storeBill(Request $request): RedirectResponse
    {
        Bill::create($this->billData($request));

        return back()->with('success', 'Monthly bill added.');
    }

    public function updateBill(Request $request, Bill $bill): RedirectResponse
    {
        $bill->update($this->billData($request));

        return back()->with('success', 'Bill updated.');
    }

    public function destroyBill(Bill $bill): RedirectResponse
    {
        $bill->delete();

        return back()->with('success', 'Bill removed — past payments are kept.');
    }

    /** One tap: record this month's payment from the bill's account. */
    public function payBill(Request $request, Bill $bill): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $on = isset($data['occurred_on']) ? now()->parse($data['occurred_on']) : now();
        $already = MoneyTransaction::where('bill_id', $bill->id)
            ->whereBetween('occurred_on', [$on->copy()->startOfMonth()->toDateString(), $on->copy()->endOfMonth()->toDateString()])
            ->exists();

        if ($already) {
            throw ValidationException::withMessages(['bill' => "{$bill->name} is already paid for ".$on->format('F').'.']);
        }

        Ledger::save([
            'kind' => 'expense',
            'account_id' => $bill->account_id,
            'amount' => isset($data['amount']) ? $this->toCents($data['amount']) : $bill->amount,
            'category' => $bill->category,
            'counterparty' => $bill->counterparty ?: $bill->name,
            'description' => $bill->name.' — '.$on->format('M Y'),
            'occurred_on' => $on->toDateString(),
            'bill_id' => $bill->id,
        ]);

        return back()->with('success', "{$bill->name} marked paid.");
    }

    /** @return array<string, mixed> */
    private function goalData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('scope', 'personal')],
            'target' => ['required', 'numeric', 'min:1'],
            'due_on' => ['nullable', 'date'],
            'done' => ['boolean'],
        ]);

        // A goal's account is where the savings sit.
        Account::whereKey($data['account_id'])->update(['purpose' => 'savings']);

        return [...$data, 'target' => $this->toCents($data['target'])];
    }

    /** @return array<string, mixed> */
    private function billData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('scope', 'personal')],
            'category' => ['required', Rule::in(array_keys(Catalog::PERSONAL_CATEGORIES))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'day' => ['required', 'integer', 'between:1,31'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'active' => ['boolean'],
        ]);

        return [...$data, 'amount' => $this->toCents($data['amount'])];
    }
}
