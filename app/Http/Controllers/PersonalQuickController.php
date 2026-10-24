<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\JsonResponse;

/** What the quick-add sheet needs: accounts with balances, categories, recent payees. */
class PersonalQuickController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $accounts = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        $ids = $accounts->pluck('id');
        $recent = MoneyTransaction::whereIn('account_id', $ids)->latest('id')->limit(200)->get(['account_id', 'counterparty', 'category', 'kind']);

        return response()->json([
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
            ])->values(),
            'categories' => [
                'expense' => Catalog::options(collect(Catalog::PERSONAL_CATEGORIES)->except('bank_fees')->all()),
                'income' => Catalog::options(Catalog::INCOME_CATEGORIES['personal']),
            ],
            // Most-used payees first, with the category last used for each.
            'payees' => $recent->whereNotNull('counterparty')->groupBy('counterparty')
                ->sortByDesc(fn ($rows) => $rows->count())->take(40)
                ->map(fn ($rows, $name) => ['name' => $name, 'category' => $rows->first()->category, 'kind' => $rows->first()->kind])->values(),
            'last_account_id' => $recent->first()?->account_id,
        ]);
    }
}
