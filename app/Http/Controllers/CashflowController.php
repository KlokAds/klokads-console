<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\CashflowReport;
use App\Services\StripeService;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One ledger of everything: Stripe money in, Stripe fees, refunds, payouts and our own expenses. */
class CashflowController extends Controller
{
    private const TYPE_LABELS = [
        'charge' => 'Payment received',
        'refund' => 'Refund',
        'dispute' => 'Dispute',
        'fee' => 'Stripe fee',
        'payout' => 'Payout to bank',
        'payout_reversal' => 'Payout reversed',
        'adjustment' => 'Adjustment',
    ];

    public function index(Request $request, StripeService $stripe): Response
    {
        $report = CashflowReport::forPeriod($request->input('period', 'this_month'), $request->input('from'), $request->input('to'));

        return Inertia::render('Cashflow/Index', [
            'entries' => $this->entries($report, $request->input('kind'))->take(1000)->values(),
            'summary' => $report->summary(),
            'filters' => [
                'period' => $request->input('period', 'this_month'),
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
                'kind' => $request->input('kind'),
            ],
            'currency' => $stripe->currency(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $report = CashflowReport::forPeriod($request->input('period', 'this_month'), $request->input('from'), $request->input('to'));
        $entries = $this->entries($report, $request->input('kind'));
        $name = 'klokads-cashflow-'.$report->from->toDateString().'-to-'.$report->to->toDateString().'.csv';

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Client', 'Description', 'Method', 'Gross', 'Stripe fee', 'Net', 'Currency', 'Reference']);
            foreach ($entries as $e) {
                fputcsv($out, [
                    $e['date'], $e['type'], $e['client'], $e['description'], $e['method'],
                    number_format($e['amount'] / 100, 2, '.', ''),
                    number_format($e['fee'] / 100, 2, '.', ''),
                    number_format($e['net'] / 100, 2, '.', ''),
                    strtoupper($e['currency']), $e['reference'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function entries(CashflowReport $report, ?string $kind): Collection
    {
        $stripeRows = collect();
        $expenseRows = collect();

        if ($kind !== 'expenses') {
            $stripeRows = Transaction::with('client:id,name,company')
                ->whereBetween('occurred_at', [$report->from, $report->to])
                ->when($kind === 'income', fn ($q) => $q->where('reporting_category', 'charge'))
                ->when($kind === 'payouts', fn ($q) => $q->whereIn('reporting_category', CashflowReport::PAYOUT_CATEGORIES))
                ->get()
                ->map(fn (Transaction $t) => [
                    'key' => 'tx-'.$t->id,
                    'source' => 'stripe',
                    'date' => $t->occurred_at->toDateString(),
                    'sort' => $t->occurred_at->timestamp,
                    'type' => self::TYPE_LABELS[$t->reporting_category] ?? str($t->reporting_category ?? $t->type)->headline()->toString(),
                    'category' => $t->reporting_category,
                    'client' => $t->client ? ($t->client->company ?: $t->client->name) : null,
                    'client_id' => $t->client_id,
                    'description' => $t->description,
                    'method' => $t->payment_method,
                    'amount' => $t->amount,
                    'fee' => $t->fee,
                    'net' => $t->net,
                    'currency' => $t->currency,
                    'status' => $t->status,
                    'reference' => $t->source_id ?: $t->stripe_id,
                ]);
        }

        if (in_array($kind, [null, '', 'expenses'], true)) {
            $expenseRows = Expense::with('client:id,name,company')
                ->whereBetween('spent_on', $report->expenseRange())
                ->get()
                ->map(fn (Expense $e) => [
                    'key' => 'ex-'.$e->id,
                    'source' => 'expense',
                    'date' => $e->spent_on->toDateString(),
                    'sort' => $e->spent_on->endOfDay()->timestamp,
                    'type' => Catalog::EXPENSE_CATEGORIES[$e->category] ?? $e->category,
                    'category' => $e->category,
                    'client' => $e->client ? ($e->client->company ?: $e->client->name) : null,
                    'client_id' => $e->client_id,
                    'description' => trim(($e->vendor ? $e->vendor.' — ' : '').($e->description ?? ''), ' —'),
                    'method' => $e->paid_via,
                    'amount' => -$e->amount,
                    'fee' => 0,
                    'net' => -$e->amount,
                    'currency' => strtolower(Setting::get('currency', 'sgd')),
                    'status' => null,
                    'reference' => $e->reference,
                ]);
        }

        return $stripeRows->concat($expenseRows)->sortByDesc('sort')->values();
    }
}
