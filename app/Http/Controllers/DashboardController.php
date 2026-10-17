<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payout;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Services\CashflowReport;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, StripeService $stripe): Response
    {
        $request->session()->put('mode', 'business');

        $period = $request->string('period', 'this_month')->toString();
        $report = CashflowReport::forPeriod($period, $request->input('from'), $request->input('to'));

        try {
            $balance = $stripe->balance();
            $balanceError = null;
        } catch (Throwable $e) {
            $balance = null;
            $balanceError = $e->getMessage();
        }

        return Inertia::render('Dashboard', [
            'filters' => [
                'period' => $period,
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
            ],
            'connected' => StripeService::configured(),
            'lastSyncAt' => Setting::get('last_sync_at'),
            'currency' => $stripe->currency(),
            'balance' => $balance,
            'balanceError' => $balanceError,
            'summary' => $report->summary(),
            'previous' => ($previous = $report->previous($period)) ? [
                'summary' => $previous->summary(),
                'label' => $previous->from->format('j M').' – '.$previous->to->format('j M Y'),
            ] : null,
            'receivables' => CashflowReport::receivables(),
            'monthly' => CashflowReport::monthly(),
            'paymentMethods' => $report->paymentMethods(),
            'expensesByCategory' => $report->expensesByCategory(),
            'services' => $report->services(),
            'clients' => $report->clients(),
            'recentInvoices' => Invoice::with('client:id,name,company')->latest('issued_at')->limit(6)->get(),
            'upcomingRenewals' => RecurringPlan::with('client:id,name,company')
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->whereNotNull('current_period_end')->orderBy('current_period_end')->limit(5)->get(),
            'recentPayouts' => Payout::latest('occurred_at')->limit(5)->get(),
        ]);
    }
}
