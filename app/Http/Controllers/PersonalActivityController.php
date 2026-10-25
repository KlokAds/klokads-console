<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\Ledger;
use App\Support\People;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Personal Activity: every entry of a month (by day), my accounts, and month totals per currency. */
class PersonalActivityController extends Controller
{
    /** Periods to look at the books by. A month starts on the 1st, a year on 1 January. */
    public const PERIODS = ['today', 'yesterday', '15d', 'month', '3m', '6m', '1y', 'year', 'custom'];

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public static function range(Request $request, string $month): array
    {
        $today = CarbonImmutable::today();
        $period = in_array($request->input('period'), self::PERIODS, true) ? $request->input('period') : 'month';
        $date = fn (?string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? CarbonImmutable::parse($v)->startOfDay() : null;

        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '15d' => [$today->subDays(14), $today],
            '3m' => [$today->subMonthsNoOverflow(3)->addDay(), $today],
            '6m' => [$today->subMonthsNoOverflow(6)->addDay(), $today],
            '1y' => [$today->subYear()->addDay(), $today],
            'year' => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
            'custom' => [$date($request->input('from')) ?? $today->startOfMonth(), $date($request->input('to')) ?? $today],
            default => [($m = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfDay()), $m->endOfMonth()->startOfDay()],
        };

        return [$period, $start->min($end), $end->max($start)];
    }

    public function __invoke(Request $request, PersonalDashboardController $dashboard): Response
    {
        $request->session()->put('mode', 'personal');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');
        [$period, $start, $end] = self::range($request, $month);

        $all = Account::where('scope', 'personal')->orderBy('archived')->orderBy('name')->get();
        $ids = $all->pluck('id')->all();
        // Business accounts kept here too (their money is never in my personal totals).
        $business = Account::where('scope', 'business')->where('archived', false)->orderBy('name')->get();
        $savingsIds = $all->where('purpose', 'savings')->pluck('id')->all();

        $transactions = MoneyTransaction::with('account:id,name,currency,scope,provider,purpose', 'toAccount:id,name,currency,scope,provider,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString().' 23:59:59'])
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', Catalog::SADAQAH))
            ->latest('occurred_on')->latest('id')
            ->get();

        $currencies = collect(Catalog::PERSONAL_CURRENCIES)->merge($all->pluck('currency')->map(fn ($c) => strtoupper($c)))->unique()->values();

        return Inertia::render('Personal/Activity', [
            'month' => $month,
            'period' => ['key' => $period, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'accounts' => $all->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4', 'app_profile', 'in_fee_pct', 'in_fee_fixed', 'out_fee_pct', 'out_fee_fixed', 'opening_balance', 'notes', 'guide', 'bank_balance', 'archived', 'scope'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
                'bank_balance_at' => $a->bank_balance_at?->toIso8601String(),
            ])->values(),
            'entries' => $transactions->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'for_whom', 'description', 'bill_id'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account?->only('id', 'name', 'currency', 'provider'),
                'to_account' => $t->toAccount?->only('id', 'name', 'currency', 'provider'),
                'direction' => Ledger::direction($t, $ids),
                'auto' => str_starts_with((string) $t->description, 'Auto ·'),
            ])->values(),
            'totals' => $currencies->mapWithKeys(fn ($c) => [$c => $dashboard->summary($transactions, $ids, $savingsIds, $c)]),
            // What each person cost in this period (spending from my accounts, fee included).
            'byPerson' => $transactions->where('kind', 'expense')->whereNotNull('for_whom')->filter(fn ($t) => in_array($t->account_id, $ids, true))
                ->groupBy(fn ($t) => strtoupper($t->account->currency).'|'.$t->for_whom)
                ->map(fn ($list, $key) => ['currency' => explode('|', $key)[0], 'person' => explode('|', $key, 2)[1], 'amount' => (int) $list->sum(fn ($t) => $t->amount + $t->fee), 'count' => $list->count()])
                ->sortByDesc('amount')->values(),
            'people' => People::all(),
            'sadaqah' => MoneyTransaction::with('account:id,currency')->whereIn('account_id', $ids)->where('category', Catalog::SADAQAH)
                ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString().' 23:59:59'])->get()
                ->groupBy(fn ($t) => strtoupper($t->account->currency))->map(fn ($list) => (int) $list->sum(fn ($t) => $t->amount + $t->fee)),
            'categories' => Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['personal'] + Catalog::LOAN_CATEGORIES,
            'providers' => Catalog::PROVIDERS,
            'kinds' => Catalog::options(Catalog::ACCOUNT_KINDS),
            'currencies' => Catalog::PERSONAL_CURRENCIES,
            'business' => $business->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'kind', 'purpose', 'provider', 'last4', 'app_profile', 'in_fee_pct', 'in_fee_fixed', 'out_fee_pct', 'out_fee_fixed', 'opening_balance', 'notes', 'guide', 'bank_balance', 'archived', 'scope'),
                'currency' => strtoupper($a->currency),
                'balance' => Ledger::balance($a),
            ])->values(),
            'mfs' => Catalog::MFS,
            'guides' => Catalog::WITHDRAW_GUIDES,
            'familyGuide' => ['url' => route('guide.show', GuideController::token()), 'message' => Setting::get('guide_message')],
        ]);
    }
}
