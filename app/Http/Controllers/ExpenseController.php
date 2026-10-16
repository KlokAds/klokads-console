<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Expense;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request, StripeService $stripe): Response
    {
        $query = Expense::query()
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->month, fn ($q, $m) => $q->where('spent_on', 'like', "{$m}%"));

        return Inertia::render('Expenses/Index', [
            'expenses' => (clone $query)->with('client:id,name,company')->latest('spent_on')->latest('id')->paginate(30)->withQueryString(),
            'total' => (int) (clone $query)->sum('amount'),
            'byCategory' => (clone $query)->selectRaw('category, sum(amount) as total')->groupBy('category')->pluck('total', 'category'),
            'filters' => $request->only('category', 'client_id', 'month'),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company']),
            'categories' => Catalog::options(Catalog::EXPENSE_CATEGORIES),
            'paidVia' => Catalog::options(Catalog::PAID_VIA),
            'currency' => $stripe->currency(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::create($this->validated($request));

        return back()->with('success', 'Expense recorded.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $expense->update($this->validated($request));

        return back()->with('success', 'Expense updated.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return back()->with('success', 'Expense deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Catalog::EXPENSE_CATEGORIES))],
            'client_id' => ['nullable', 'exists:clients,id'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'original_amount' => ['nullable', 'numeric', 'min:0'],
            'original_currency' => ['nullable', 'string', 'size:3'],
            'paid_via' => ['nullable', Rule::in(array_keys(Catalog::PAID_VIA))],
            'reference' => ['nullable', 'string', 'max:255'],
            'spent_on' => ['required', 'date'],
        ]);

        $data['amount'] = $this->toCents($data['amount']);

        if (filled($data['original_amount'] ?? null) && filled($data['original_currency'] ?? null)) {
            $data['original_amount'] = $this->toCents($data['original_amount']);
            $data['original_currency'] = strtolower($data['original_currency']);
        } else {
            $data['original_amount'] = null;
            $data['original_currency'] = null;
        }

        return $data;
    }
}
