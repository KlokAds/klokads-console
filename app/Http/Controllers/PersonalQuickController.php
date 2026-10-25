<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Support\Catalog;
use App\Support\Ledger;
use App\Support\People;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the quick-add sheet needs: accounts with balances, categories, recent payees. */
class PersonalQuickController extends Controller
{
    /** The family list behind "For whom" (add / remove / reorder). */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate(['people' => ['present', 'array', 'max:40'], 'people.*.name' => ['required', 'string', 'max:40'], 'people.*.emoji' => ['nullable', 'string', 'max:16']]);

        return response()->json(['people' => People::save($data['people'])]);
    }

    public function __invoke(): JsonResponse
    {
        $accounts = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        $ids = $accounts->pluck('id');
        $recent = MoneyTransaction::whereIn('account_id', $ids)->latest('id')->limit(200)->get(['account_id', 'counterparty', 'category', 'kind']);

        return response()->json([
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
            ])->values(),
            // Moves can go to / come from a business account (capital in, owner draw out).
            'business' => Account::where('scope', 'business')->where('archived', false)->orderBy('name')->get()->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
                'business' => true,
            ])->values(),
            'categories' => [
                'expense' => Catalog::options(collect(Catalog::PERSONAL_CATEGORIES)->except(Catalog::AUTO_CATEGORIES)->all()),
                'income' => Catalog::options(Catalog::INCOME_CATEGORIES['personal']),
                'business_expense' => Catalog::options(Catalog::EXPENSE_CATEGORIES),
                'business_income' => Catalog::options(Catalog::INCOME_CATEGORIES['business']),
            ],
            // Most-used payees first, with the category last used for each.
            'payees' => $recent->whereNotNull('counterparty')->groupBy('counterparty')
                ->sortByDesc(fn ($rows) => $rows->count())->take(40)
                ->map(fn ($rows, $name) => ['name' => $name, 'category' => $rows->first()->category, 'kind' => $rows->first()->kind])->values(),
            'last_account_id' => $recent->first()?->account_id,
            'people' => People::all(),
            // Open invoices an incoming entry can be marked as the payment for.
            'invoices' => PersonalInvoice::with('client:id,name,company')->whereIn('status', ['unpaid', 'partial'])->latest('issued_on')->get()
                ->map(fn (PersonalInvoice $i) => ['id' => $i->id, 'number' => $i->number, 'client' => $i->client->company ?: $i->client->name, 'due' => $i->due(), 'currency' => $i->currency])->values(),
            'linked' => PersonalInvoicePayment::whereNotNull('money_transaction_id')->pluck('money_transaction_id'),
        ]);
    }
}
