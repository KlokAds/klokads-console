<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The personal side: SGD and BDT kept apart (nothing converted), where each balance sits,
 * what was spent this month and out of which account.
 */
class PersonalDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->session()->put('mode', 'personal');

        $accounts = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        $ids = $accounts->pluck('id')->all();
        $from = now()->startOfMonth()->subMonths(5);
        $thisMonth = now()->format('Y-m');

        $transactions = MoneyTransaction::with('account:id,name,currency,scope,provider', 'toAccount:id,name,currency,scope,provider')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', '>=', $from->toDateString())
            ->latest('occurred_on')->latest('id')
            ->get();

        $currencies = collect(Catalog::PERSONAL_CURRENCIES)->merge($accounts->pluck('currency'))->map(fn ($c) => strtoupper($c))->unique()->values();
        $monthKeys = collect(range(0, 5))->map(fn (int $i) => $from->copy()->addMonths($i));

        $stats = $currencies->mapWithKeys(function (string $currency) use ($transactions, $ids, $monthKeys) {
            $months = $monthKeys->map(fn ($m) => [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M'),
                ...$this->summary($transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $m->format('Y-m')), $ids, $currency),
            ]);

            return [$currency => ['months' => $months->values(), 'current' => $months->last(), 'previous' => $months->get(4)]];
        });

        $monthTx = $transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $thisMonth);

        return Inertia::render('Personal/Dashboard', [
            'currencies' => $currencies,
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'provider', 'currency'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
                // What left this account this month (spending + fees).
                'month_out' => (int) $monthTx->where('account_id', $a->id)->sum(fn ($t) => $t->kind === 'expense' ? $t->amount + $t->fee : $t->fee),
            ]),
            'stats' => $stats,
            'recent' => $transactions->take(10)->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'description'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account,
                'to_account' => $t->toAccount,
                'direction' => Ledger::direction($t, $ids),
            ])->values(),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'],
            'providers' => collect(Catalog::PROVIDERS)->flatMap(fn ($list) => collect($list)->map(fn ($p) => $p[0])),
        ]);
    }

    /**
     * One currency's month, in that currency: what came in, what was spent (and on what),
     * what moved out to business. Conversions between your own accounts are neither.
     *
     * @param  Collection<int, MoneyTransaction>  $transactions
     * @param  array<int, int>  $ids
     * @return array{in: int, spent: int, out: int, net: int, by_category: array<string, int>}
     */
    private function summary(Collection $transactions, array $ids, string $currency): array
    {
        $in = $spent = $out = 0;
        $byCategory = [];
        $add = function (string $key, int $amount) use (&$byCategory, &$spent) {
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $amount;
            $spent += $amount;
        };

        foreach ($transactions as $t) {
            $fromMine = in_array($t->account_id, $ids, true) && strtoupper($t->account->currency) === $currency;
            $toMine = $t->to_account_id && in_array($t->to_account_id, $ids, true) && strtoupper($t->toAccount->currency) === $currency;

            if ($fromMine && $t->fee) {
                $add('bank_fees', $t->fee);
            }

            if ($t->kind === 'expense' && $fromMine) {
                $add($t->category ?: 'other', $t->amount);
            } elseif ($t->kind === 'income' && $fromMine) {
                $in += $t->amount; // its fee is already counted as spent above
            } elseif ($t->kind === 'transfer') {
                $fromPersonal = in_array($t->account_id, $ids, true);
                $toPersonal = $t->to_account_id && in_array($t->to_account_id, $ids, true);

                if ($toMine && ! $fromPersonal) {
                    $in += (int) $t->to_amount; // e.g. salary moved in from business
                } elseif ($fromMine && ! $toPersonal) {
                    $out += $t->amount; // sent to a business account
                }
            }
        }

        arsort($byCategory);

        return ['in' => $in, 'spent' => $spent, 'out' => $out, 'net' => $in - $spent - $out, 'by_category' => $byCategory];
    }
}
