<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The personal side: your own accounts, what you spend each month and where. */
class PersonalDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->session()->put('mode', 'personal');

        $accounts = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        $ids = $accounts->pluck('id')->all();
        $from = now()->startOfMonth()->subMonths(5);

        $transactions = MoneyTransaction::with('account:id,name,currency,scope', 'toAccount:id,name,currency,scope')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', '>=', $from->toDateString())
            ->latest('occurred_on')->latest('id')
            ->get();

        // Last 6 months, oldest first.
        $months = collect(range(0, 5))->map(function (int $i) use ($from, $transactions, $ids) {
            $month = $from->copy()->addMonths($i);
            $summary = Ledger::summary($transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $month->format('Y-m')), $ids);

            return ['month' => $month->format('Y-m'), 'label' => $month->format('M'), ...$summary];
        });

        $current = $months->last();
        $previous = $months->get(4);

        return Inertia::render('Personal/Dashboard', [
            'base' => Ledger::base(),
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'currency'),
                'balance' => $balance = Ledger::balance($a),
                'base_value' => Ledger::value($a, $balance),
            ]),
            'current' => $current,
            'previous' => $previous,
            'months' => $months->values(),
            'recent' => $transactions->take(8)->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'amount', 'to_amount', 'fee', 'base_amount', 'category', 'counterparty', 'description'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account,
                'to_account' => $t->toAccount,
                'direction' => Ledger::direction($t, $ids),
            ])->values(),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'],
        ]);
    }
}
