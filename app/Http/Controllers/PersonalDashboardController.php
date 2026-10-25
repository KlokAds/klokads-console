<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\Loan;
use App\Models\MoneyTransaction;
use App\Services\Envelopes;
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
        return Inertia::render('Personal/Dashboard', $this->data($request));
    }

    /** Budgets, savings goals and monthly bills in one place. */
    public function plan(Request $request): Response
    {
        return Inertia::render('Personal/Plan', [
            ...$this->data($request),
            'envelopes' => app(Envelopes::class)->summary(now()->format('Y-m')),
            'month' => now()->format('Y-m'),
        ]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $request->session()->put('mode', 'personal');

        $all = Account::where('scope', 'personal')->where('archived', false)->orderBy('name')->get();
        // Moves into an investment stay "mine" (ids include them); balances and cards show money only.
        $ids = $all->pluck('id')->all();
        $investments = $all->where('kind', 'investment')->values();
        $accounts = $all->where('kind', '!=', 'investment')->values();
        // Putting money into an investment counts as saving it.
        $savingsIds = $all->filter(fn ($a) => $a->purpose === 'savings' || $a->kind === 'investment')->pluck('id')->all();
        $loans = $this->loanSummary();
        $from = now()->startOfMonth()->subMonths(5);
        $thisMonth = now()->format('Y-m');

        $transactions = MoneyTransaction::with('account:id,name,currency,scope,provider,purpose', 'toAccount:id,name,currency,scope,provider,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', '>=', $from->toDateString())
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', Catalog::SADAQAH))
            ->latest('occurred_on')->latest('id')
            ->get();

        $currencies = collect(Catalog::PERSONAL_CURRENCIES)->merge($accounts->pluck('currency'))->map(fn ($c) => strtoupper($c))->unique()->values();
        $monthKeys = collect(range(0, 5))->map(fn (int $i) => $from->copy()->addMonths($i));
        $balances = $accounts->mapWithKeys(fn (Account $a) => [$a->id => Ledger::balance($a)]);
        $monthTx = $transactions->filter(fn ($t) => $t->occurred_on->format('Y-m') === $thisMonth);
        $budgets = Budget::all();

        // This year, from 1 January: a new year starts again from zero.
        $yearTx = MoneyTransaction::with('account:id,name,currency,scope,provider,purpose', 'toAccount:id,name,currency,scope,provider,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', '>=', now()->startOfYear()->toDateString())
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', Catalog::SADAQAH))
            ->get();

        $stats = $currencies->mapWithKeys(function (string $currency) use ($transactions, $yearTx, $ids, $savingsIds, $monthKeys, $accounts, $balances, $budgets, $investments, $loans) {
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
                'year' => $this->summary($yearTx, $ids, $savingsIds, $currency),
                // Sadaqah: just this month's total.
                'sadaqah' => (int) MoneyTransaction::whereIn('account_id', $mine->pluck('id'))->where('category', Catalog::SADAQAH)
                    ->where('occurred_on', '>=', now()->startOfMonth()->toDateString())->get()->sum(fn ($t) => $t->amount + $t->fee),
                'total' => (int) $mine->sum(fn ($a) => $balances[$a->id]),
                'savings' => (int) $mine->where('purpose', 'savings')->sum(fn ($a) => $balances[$a->id]),
                'spending' => (int) $mine->where('purpose', '!=', 'savings')->sum(fn ($a) => $balances[$a->id]),
                // Investments at what they are worth now; net worth = money + investments + owed to me − I owe.
                'investments' => $invested = (int) $investments->filter(fn ($a) => strtoupper($a->currency) === $currency)->sum(fn ($a) => $a->current_value ?? Ledger::balance($a)),
                'net_worth' => (int) $mine->sum(fn ($a) => $balances[$a->id]) + $invested
                    + ($loans['totals'][$currency]['to_receive'] ?? 0) - ($loans['totals'][$currency]['to_pay'] ?? 0),
                'budgets' => $budgets->where('currency', $currency)->map(fn (Budget $b) => [
                    'category' => $b->category,
                    'label' => Catalog::PERSONAL_CATEGORIES[$b->category] ?? $b->category,
                    'limit' => $b->amount,
                    'spent' => $current['by_category'][$b->category] ?? 0,
                ])->sortByDesc(fn ($b) => $b['spent'] / max(1, $b['limit']))->values(),
            ]];
        });

        $paidBills = $monthTx->whereNotNull('bill_id')->keyBy('bill_id');

        return [
            'currencies' => $currencies,
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4'),
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
            'recent' => $transactions->take(8)->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'for_whom', 'description'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account,
                'to_account' => $t->toAccount,
                'direction' => Ledger::direction($t, $ids),
            ])->values(),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'] + Catalog::LOAN_CATEGORIES,
            'loans' => $loans,
            'spendCategories' => Catalog::options(Catalog::PERSONAL_CATEGORIES),
            'providers' => collect(Catalog::PROVIDERS)->flatMap(fn ($list) => collect($list)->map(fn ($p) => $p[0])),
        ];
    }

    /**
     * Dena-paona at a glance: totals per currency and what is due soon or overdue.
     *
     * @return array<string, mixed>
     */
    private function loanSummary(): array
    {
        $open = Loan::with('payments')->get()->filter(fn (Loan $l) => $l->outstanding() > 0);

        return [
            'totals' => collect(Catalog::PERSONAL_CURRENCIES)->mapWithKeys(fn ($c) => [$c => [
                'to_receive' => (int) $open->where('direction', 'lent')->where('currency', $c)->sum(fn ($l) => $l->outstanding()),
                'to_pay' => (int) $open->where('direction', 'borrowed')->where('currency', $c)->sum(fn ($l) => $l->outstanding()),
            ]]),
            'soon' => $open->filter(fn ($l) => $l->due_on && $l->due_on->lte(today()->addDays(7)))->sortBy('due_on')->take(5)
                ->map(fn ($l) => [...$l->only('id', 'direction', 'person', 'currency'), 'outstanding' => $l->outstanding(),
                    'due_on' => $l->due_on->toDateString(), 'overdue' => $l->due_on->lt(today())])->values(),
            'count' => $open->count(),
        ];
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
    public function summary(Collection $transactions, array $ids, array $savingsIds, string $currency): array
    {
        $in = $spent = $saved = $out = 0;
        $byCategory = [];
        $add = function (string $key, int $amount) use (&$byCategory, &$spent) {
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $amount;
            $spent += $amount;
        };

        foreach ($transactions as $t) {
            $fromMine = in_array($t->account_id, $ids, true) && strtoupper($t->account->currency) === $currency;
            // Dena-paona moves money but is neither spending nor income — only its sending fee is spent.
            if ($t->kind !== 'transfer' && array_key_exists((string) $t->category, Catalog::LOAN_CATEGORIES)) {
                if ($fromMine && $t->fee) {
                    $add('bank_fees', $t->fee);
                }

                continue;
            }
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
