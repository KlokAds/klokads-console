<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RecurringPlan;
use App\Models\Transaction;
use App\Support\Catalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * All money figures are integers in the smallest currency unit (cents) of the
 * Stripe settlement currency (SGD for a Singapore account).
 */
class CashflowReport
{
    /** Balance-transaction categories that are money moving out to the bank, not income. */
    public const PAYOUT_CATEGORIES = ['payout', 'payout_reversal', 'topup', 'topup_reversal', 'transfer', 'transfer_reversal'];

    public const REFUND_CATEGORIES = ['refund', 'refund_failure', 'dispute', 'dispute_reversal'];

    public function __construct(public Carbon $from, public Carbon $to) {}

    public static function forPeriod(string $period, ?string $from = null, ?string $to = null): self
    {
        $now = now();

        [$start, $end] = match ($period) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->endOfDay()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last_12_months' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfDay()],
            'all' => [Carbon::create(2000), $now->copy()->endOfDay()],
            'custom' => [
                Carbon::parse($from ?: $now->copy()->startOfMonth())->startOfDay(),
                Carbon::parse($to ?: $now)->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };

        return new self($start, $end);
    }

    /**
     * The comparable period just before this one: the same days a month / quarter / year
     * earlier for calendar periods, or an equal-length window before a custom range.
     */
    public function previous(string $period): ?self
    {
        $shift = ['this_month' => 1, 'last_month' => 1, 'last_3_months' => 3, 'this_year' => 12, 'last_12_months' => 12][$period] ?? null;

        if ($period === 'all') {
            return null;
        }

        if ($shift) {
            $to = $this->to->copy()->subMonthsNoOverflow($shift);
            if ($period === 'last_month') {
                $to = $to->endOfMonth();
            }

            return new self($this->from->copy()->subMonthsNoOverflow($shift), $to);
        }

        $days = (int) $this->from->diffInDays($this->to) + 1;

        return new self($this->from->copy()->subDays($days), $this->from->copy()->subSecond());
    }

    private function transactions(): Collection
    {
        return Transaction::whereBetween('occurred_at', [$this->from, $this->to])->get();
    }

    /**
     * @return array{gross: int, refunds: int, fees: int, net: int, payouts: int, expenses: int, profit: int, fee_rate: float, payments_count: int}
     */
    public function summary(): array
    {
        $tx = $this->transactions();

        return $this->summarize($tx, $this->expenseTotal($this->from, $this->to)) + $this->commission();
    }

    /**
     * Commission earned and ad budget received on ad-budget invoices paid in the period.
     *
     * @return array{commission: int, ad_budget_received: int, budget_invoices: int, client_spend: int}
     */
    public function commission(): array
    {
        $paid = Invoice::where('status', 'paid')->whereNotNull('net_amount')->whereBetween('paid_at', [$this->from, $this->to]);

        return [
            // Pay-later invoices carry their commission; prepaid clients earn it as each day is deducted.
            'commission' => (int) (clone $paid)->sum('commission_amount') + $this->prepaidCommission()->sum(),
            'ad_budget_received' => (int) (clone $paid)->sum('ad_budget'),
            'budget_invoices' => (clone $paid)->count(),
            // Ad spend clients paid the platforms themselves, on commission-only invoices.
            'client_spend' => (int) (clone $paid)->sum('client_spend'),
        ];
    }

    /** Commission earned on prepaid balances in the period, by client. */
    private function prepaidCommission(): Collection
    {
        return ClientBalanceEntry::where('kind', 'spend')
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('client_id, sum(commission) as total')->groupBy('client_id')->pluck('total', 'client_id')
            ->map(fn ($v) => (int) $v);
    }

    /** Expense categories that are money spent on ad platforms on the client's behalf. */
    public const AD_SPEND_CATEGORIES = ['meta_ads', 'google_ads', 'tiktok_ads'];

    /**
     * All-time ad budget each client paid us vs what has been spent on their ads.
     *
     * @return array<int, array{received: int, spent: int, left: int}> keyed by client id
     */
    public static function budgetBalances(?int $clientId = null): array
    {
        $received = Invoice::where('status', 'paid')->whereNotNull('ad_budget')->whereNotNull('client_id')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->selectRaw('client_id, sum(ad_budget) as total')->groupBy('client_id')->pluck('total', 'client_id');

        $spent = Expense::whereIn('category', self::AD_SPEND_CATEGORIES)->whereNotNull('client_id')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->selectRaw('client_id, sum(amount) as total')->groupBy('client_id')->pluck('total', 'client_id');

        return $received->keys()->merge($spent->keys())->unique()
            ->mapWithKeys(fn ($id) => [$id => [
                'received' => (int) ($received[$id] ?? 0),
                'spent' => (int) ($spent[$id] ?? 0),
                'left' => (int) ($received[$id] ?? 0) - (int) ($spent[$id] ?? 0),
            ]])->all();
    }

    private function summarize(Collection $tx, int $expenses): array
    {
        $charges = $tx->where('reporting_category', 'charge');
        $gross = (int) $charges->sum('amount');
        $refunds = (int) -$tx->whereIn('reporting_category', self::REFUND_CATEGORIES)->sum('amount');

        $nonPayout = $tx->whereNotIn('reporting_category', self::PAYOUT_CATEGORIES);
        // Processing fees sit on each transaction; Stripe billing/invoicing fees are their own "fee" rows.
        $fees = (int) $nonPayout->sum('fee') - (int) $nonPayout->where('reporting_category', 'fee')->sum('amount');
        $net = (int) $nonPayout->sum('net');
        $payouts = (int) -$tx->where('reporting_category', 'payout')->sum('amount')
            - (int) $tx->where('reporting_category', 'payout_reversal')->sum('amount');

        return [
            'gross' => $gross,
            'refunds' => $refunds,
            'fees' => $fees,
            'net' => $net,
            'payouts' => $payouts,
            'expenses' => $expenses,
            'profit' => $net - $expenses,
            'fee_rate' => $gross > 0 ? round($fees / $gross * 100, 2) : 0.0,
            'payments_count' => $charges->count(),
        ];
    }

    /** @return array{0: string, 1: string} inclusive date range for the `spent_on` column */
    public function expenseRange(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString().' 23:59:59'];
    }

    private function expenseTotal(Carbon $from, Carbon $to): int
    {
        return (int) Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString().' 23:59:59'])->sum('amount');
    }

    /** @return array<int, array{method: string, count: int, amount: int, fees: int}> */
    public function paymentMethods(): array
    {
        return $this->transactions()
            ->where('reporting_category', 'charge')
            ->groupBy(fn ($t) => $t->payment_method ?: 'other')
            ->map(fn ($rows, $method) => [
                'method' => $method,
                'count' => $rows->count(),
                'amount' => (int) $rows->sum('amount'),
                'fees' => (int) $rows->sum('fee'),
            ])
            ->sortByDesc('amount')->values()->all();
    }

    /** @return array<int, array{key: string, label: string, amount: int}> */
    public function expensesByCategory(): array
    {
        return Expense::whereBetween('spent_on', $this->expenseRange())
            ->selectRaw('category, sum(amount) as total')->groupBy('category')->get()
            ->map(fn ($r) => [
                'key' => $r->category,
                'label' => Catalog::EXPENSE_CATEGORIES[$r->category] ?? $r->category,
                'amount' => (int) $r->total,
            ])
            ->sortByDesc('amount')->values()->all();
    }

    /**
     * Revenue (paid invoices) against spend (expenses) for each service line.
     *
     * @return array<int, array{key: string, label: string, revenue: int, spend: int, margin: int}>
     */
    public function services(): array
    {
        $revenue = Invoice::whereBetween('paid_at', [$this->from, $this->to])
            ->selectRaw('service, sum(amount_paid) as total')->groupBy('service')->pluck('total', 'service');

        $spend = Expense::whereBetween('spent_on', $this->expenseRange())
            ->selectRaw('category, sum(amount) as total')->groupBy('category')->pluck('total', 'category');

        return collect(Catalog::SERVICES)
            ->map(function ($label, $key) use ($revenue, $spend) {
                $in = (int) ($revenue[$key] ?? 0) + ($key === 'other' ? (int) ($revenue[''] ?? 0) : 0);
                $out = (int) ($spend[$key] ?? 0);

                return ['key' => $key, 'label' => $label, 'revenue' => $in, 'spend' => $out, 'margin' => $in - $out];
            })
            ->filter(fn ($s) => $s['revenue'] || $s['spend'])
            ->values()->all();
    }

    /** @return array<int, array{id: int, name: string, revenue: int, fees: int, spend: int, profit: int, outstanding: int}> */
    public function clients(int $limit = 10): array
    {
        $tx = Transaction::whereBetween('occurred_at', [$this->from, $this->to])
            ->whereNotNull('client_id')->whereNotIn('reporting_category', self::PAYOUT_CATEGORIES)
            ->selectRaw('client_id, sum(case when reporting_category = ? then amount else 0 end) as revenue, sum(fee) as fees, sum(net) as net', ['charge'])
            ->groupBy('client_id')->get()->keyBy('client_id');

        $spend = Expense::whereBetween('spent_on', $this->expenseRange())
            ->whereNotNull('client_id')->selectRaw('client_id, sum(amount) as total')
            ->groupBy('client_id')->pluck('total', 'client_id');

        $outstanding = Invoice::where('status', 'open')->whereNotNull('client_id')
            ->selectRaw('client_id, sum(amount_remaining) as total')->groupBy('client_id')->pluck('total', 'client_id');

        $ids = $tx->keys()->merge($spend->keys())->merge($outstanding->keys())->unique();

        $commission = Invoice::where('status', 'paid')->whereNotNull('client_id')->whereNotNull('commission_amount')
            ->whereBetween('paid_at', [$this->from, $this->to])
            ->selectRaw('client_id, sum(commission_amount) as total')->groupBy('client_id')->pluck('total', 'client_id');
        foreach ($this->prepaidCommission() as $clientId => $earned) {
            $commission[$clientId] = ($commission[$clientId] ?? 0) + $earned;
        }
        $budgets = self::budgetBalances();

        return Client::whereIn('id', $ids)->get()
            ->map(function (Client $c) use ($tx, $spend, $outstanding, $commission, $budgets) {
                $row = $tx->get($c->id);
                $net = (int) ($row->net ?? 0);
                $out = (int) ($spend[$c->id] ?? 0);

                return [
                    'id' => $c->id,
                    'name' => $c->company ?: $c->name,
                    'revenue' => (int) ($row->revenue ?? 0),
                    'fees' => (int) ($row->fees ?? 0),
                    'spend' => $out,
                    'profit' => $net - $out,
                    'commission' => (int) ($commission[$c->id] ?? 0),
                    'budget_left' => $budgets[$c->id]['left'] ?? null,
                    'outstanding' => (int) ($outstanding[$c->id] ?? 0),
                ];
            })
            ->sortByDesc('revenue')->take($limit)->values()->all();
    }

    /**
     * Twelve calendar months ending with the month of $to.
     *
     * @return array<int, array{month: string, gross: int, fees: int, net: int, expenses: int, profit: int}>
     */
    public static function monthly(int $months = 12): array
    {
        $start = now()->subMonthsNoOverflow($months - 1)->startOfMonth();

        $tx = Transaction::where('occurred_at', '>=', $start)->get()
            ->groupBy(fn ($t) => $t->occurred_at->format('Y-m'));
        $expenses = Expense::where('spent_on', '>=', $start->toDateString())->get()
            ->groupBy(fn ($e) => $e->spent_on->format('Y-m'));

        $report = new self($start, now());

        return collect(range(0, $months - 1))->map(function ($i) use ($start, $tx, $expenses, $report) {
            $key = $start->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $s = $report->summarize($tx->get($key, collect()), (int) $expenses->get($key, collect())->sum('amount'));

            return [
                'month' => $key,
                'gross' => $s['gross'],
                'fees' => $s['fees'],
                'net' => $s['net'],
                'expenses' => $s['expenses'],
                'profit' => $s['profit'],
            ];
        })->all();
    }

    /** @return array{outstanding: int, overdue: int, overdue_count: int, open_count: int, mrr: int, active_plans: int} */
    public static function receivables(): array
    {
        $open = Invoice::where('status', 'open')->get();
        $overdue = $open->filter(fn ($i) => $i->due_at && $i->due_at->isPast());
        $plans = RecurringPlan::whereIn('status', ['active', 'trialing', 'past_due'])->get();

        return [
            'outstanding' => (int) $open->sum('amount_remaining'),
            'overdue' => (int) $overdue->sum('amount_remaining'),
            'overdue_count' => $overdue->count(),
            'open_count' => $open->count(),
            'mrr' => (int) round($plans->sum(fn (RecurringPlan $p) => $p->monthlyAmount())),
            'active_plans' => $plans->count(),
        ];
    }
}
