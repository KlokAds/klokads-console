<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Personal Activity: every entry of a month (by day), my accounts, and month totals per currency. */
class PersonalActivityController extends Controller
{
    public function __invoke(Request $request, PersonalDashboardController $dashboard): Response
    {
        $request->session()->put('mode', 'personal');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();

        $all = Account::where('scope', 'personal')->orderBy('archived')->orderBy('name')->get();
        $ids = $all->pluck('id')->all();
        $savingsIds = $all->where('purpose', 'savings')->pluck('id')->all();

        $transactions = MoneyTransaction::with('account:id,name,currency,scope,provider,purpose', 'toAccount:id,name,currency,scope,provider,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->whereBetween('occurred_on', [$start->toDateString(), $start->endOfMonth()->toDateString().' 23:59:59'])
            ->latest('occurred_on')->latest('id')
            ->get();

        $currencies = collect(Catalog::PERSONAL_CURRENCIES)->merge($all->pluck('currency')->map(fn ($c) => strtoupper($c)))->unique()->values();

        return Inertia::render('Personal/Activity', [
            'month' => $month,
            'accounts' => $all->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4', 'opening_balance', 'notes', 'guide', 'bank_balance', 'archived', 'scope'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
                'bank_balance_at' => $a->bank_balance_at?->toIso8601String(),
            ])->values(),
            'entries' => $transactions->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'description', 'bill_id'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account?->only('id', 'name', 'currency', 'provider'),
                'to_account' => $t->toAccount?->only('id', 'name', 'currency', 'provider'),
                'direction' => Ledger::direction($t, $ids),
                'auto' => str_starts_with((string) $t->description, 'Auto ·'),
            ])->values(),
            'totals' => $currencies->mapWithKeys(fn ($c) => [$c => $dashboard->summary($transactions, $ids, $savingsIds, $c)]),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'] + Catalog::LOAN_CATEGORIES,
            'providers' => Catalog::PROVIDERS,
            'kinds' => Catalog::options(Catalog::ACCOUNT_KINDS),
            'currencies' => Catalog::PERSONAL_CURRENCIES,
            'mfs' => Catalog::MFS,
            'guides' => Catalog::WITHDRAW_GUIDES,
            'familyGuide' => ['url' => route('guide.show', GuideController::token()), 'message' => Setting::get('guide_message')],
        ]);
    }
}
