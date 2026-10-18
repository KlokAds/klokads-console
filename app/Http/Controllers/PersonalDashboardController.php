<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\MoneyTransaction;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The personal side, one currency at a time (SGD and BDT are never converted):
 * where the money sits, what came in, what was spent, what was put aside as savings,
 * budgets, goals and this month's bills.
 */
class PersonalDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->session()->put('mode', 'personal');

        $accounts = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        $ids = $accounts->pluck('id')->all();
        $savingsIds = $accounts->where('purpose', 'savings')->pluck('id')->all();
        $from = now()->startOfMonth()->subMonths(5);
        $thisMonth = now()->format('Y-m');

        $transactions = MoneyTransaction::with('account:id,name,currency,scope,provider,purpose', 'toAccount:id,name,currency,scope,provider,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', '>=', $from->toDateString())
            ->latest('occurred_on')->latest('id')
            ->get();

        $currencies = collect(Catalog::PERSONAL_CURRENCIES)->merge($accounts->pluck('currency'))->map(fn ($c) => strtoupper($c))->unique()->values();
        $monthKeys = collect(range(0, 5))->map(fn (int $i) => $from->copy()->addMonths($i));
        $balances = $accounts->mapWithKeys(fn (Account $a) => [$a->id => Ledger::balance($a)]);
        $monthTx = $transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $thisMonth);
        $budgets = Budget::all();

        $stats = $currencies->mapWithKeys(function (string $currency) use ($transactions, $ids, $savingsIds, $monthKeys, $accounts, $balances, $budgets) {
            $months = $monthKeys->map(fn ($m) => [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M'),
                ...$this->summary($transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $m->format('Y-m')), $ids, $savingsIds, $currency),
            ]);
            $mine = $accounts->filter(fn ($a) => strtoupper($a->currency) === $currency);
            $current = $months->last();

            return [$currency => [
                'months' => $months->values(),
                'current' => $current,
                'previous' => $months->get(4),
                'total' => (int) $mine->sum(fn ($a) => $balances[$a->id]),
                'savings' => (int) $mine->where('purpose', 'savings')->sum(fn ($a) => $balances[$a->id]),
                'spending' => (int) $mine->where('purpose', '!=', 'savings')->sum(fn ($a) => $balances[$a->id]),
                'budgets' => $budgets->where('currency', $currency)->map(fn (Budget $b) => [
                    'category' => $b->category,
                    'label' => Catalog::PERSONAL_CATEGORIES[$b->category] ?? $b->category,
                    'limit' => $b->amount,
                    'spent' => $current['by_category'][$b->category] ?? 0,
                ])->sortByDesc(fn ($b) => $b['spent'] / max(1, $b['limit']))->values(),
            ]];
        });

        $paidBills = $monthTx->whereNotNull('bill_id')->keyBy('bill_id');

        return Inertia::render('Personal/Dashboard', [
            'currencies' => $currencies,
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider'),
                'currency' => strtoupper($a->currency),
                'balance' => $balances[$a->id],
                // What left this account this month (spending + fees).
                'month_out' => (int) $monthTx->where('account_id', $a->id)->sum(fn ($t) => $t->kind === 'expense' ? $t->amount + $t->fee : $t->fee),
            ])->values(),
            'stats' => $stats,
            'goals' => Goal::with('account:id,name,currency')->where('done', false)->orderBy('due_on')->get()->map(fn (Goal $g) => [
                ...$g->only('id', 'name', 'account_id', 'target', 'done'),
                'due_on' => $g->due_on?->toDateString(),
                'account' => $g->account->name,
                'currency' => strtoupper($g->account->currency),
                'saved' => $balances[$g->account_id] ?? Ledger::balance($g->account),
            ]),
            'bills' => Bill::with('account:id,name,currency')->where('active', true)->orderBy('day')->get()->map(fn (Bill $b) => [
                ...$b->only('id', 'name', 'account_id', 'category', 'amount', 'day', 'counterparty', 'active'),
                'account' => $b->account->name,
                'currency' => strtoupper($b->account->currency),
                'paid_on' => $paidBills->get($b->id)?->occurred_on->toDateString(),
                'overdue' => ! $paidBills->has($b->id) && $b->day < now()->day,
            ]),
            'recent' => $transactions->take(10)->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'description'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account,
                'to_account' => $t->toAccount,
                'direction' => Ledger::direction($t, $ids),
            ])->values(),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'],
            'spendCategories' => Catalog::options(Catalog::PERSONAL_CATEGORIES),
            'providers' => collect(Catalog::PROVIDERS)->flatMap(fn ($list) => collect($list)->map(fn ($p) => $p[0])),
        ]);
    }

    /**
     * One currency's month, in that currency.
     *  - in:    money that arrived from outside your personal accounts
     *  - spent: what you paid for things (+ bank fees)
     *  - saved: what you put into savings accounts (minus what you took back out)
     *  - left:  in − spent − saved − sent to business
     * Moving or converting between your own spending accounts is none of these.
     *
     * @param  Collection<int, MoneyTransaction>  $transactions
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $savingsIds
     * @return array{in: int, spent: int, saved: int, out: int, left: int, by_category: array<string, int>}
     */
    private function summary(Collection $transactions, array $ids, array $savingsIds, string $currency): array
    {
        $in = $spent = $saved = $out = 0;
        $byCategory = [];
        $add = function (string $key, int $amount) use (&$byCategory, &$spent) {
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $amount;
            $spent += $amount;
        };

        foreach ($transactions as $t) {
            $fromMine = in_array($t->account_id, $ids, true) && strtoupper($t->account->currency) === $currency;
            $toMine = $t->to_account_id && in_array($t->to_account_id, $ids, true) && strtoupper($t->toAccount->currency) === $currency;
            $fromSavings = in_array($t->account_id, $savingsIds, true);
            $toSavings = $t->to_account_id && in_array($t->to_account_id, $savingsIds, true);

            if ($fromMine && $t->fee) {
                $add('bank_fees', $t->fee);
            }

            if ($t->kind === 'expense' && $fromMine) {
                $add($t->category ?: 'other', $t->amount);
            } elseif ($t->kind === 'income' && $fromMine) {
                $in += $t->amount; // its fee is already counted as spent above
                if ($fromSavings) {
                    $saved += $t->amount - $t->fee; // arrived straight into savings
                }
            } elseif ($t->kind === 'transfer') {
                $fromPersonal = in_array($t->account_id, $ids, true);
                $toPersonal = $t->to_account_id && in_array($t->to_account_id, $ids, true);

                if ($toMine && ! $fromPersonal) {
                    $in += (int) $t->to_amount; // e.g. salary moved in from business
                    if ($toSavings) {
                        $saved += (int) $t->to_amount;
                    }
                } elseif ($fromMine && ! $toPersonal) {
                    $out += $t->amount; // sent to a business account
                    if ($fromSavings) {
                        $saved -= $t->amount;
                    }
                } elseif ($toMine && $toSavings && ! $fromSavings) {
                    $saved += (int) $t->to_amount; // put aside
                } elseif ($fromMine && $fromSavings && ! $toSavings) {
                    $saved -= $t->amount; // taken back out of savings
                }
            }
        }

        arsort($byCategory);

        return ['in' => $in, 'spent' => $spent, 'saved' => $saved, 'out' => $out, 'left' => $in - $spent - $saved - $out, 'by_category' => $byCategory];
    }
}
