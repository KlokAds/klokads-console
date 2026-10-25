<?php

namespace App\Http\Controllers;

use App\Models\AirwallexEntry;
use App\Models\ChargeRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Models\Payout;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Services\AdAdvice;
use App\Services\AdPerformance;
use App\Services\Airwallex;
use App\Services\CashflowReport;
use App\Services\PrepaidBalance;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
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
            'adDesk' => $this->adDesk(),
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

    /**
     * Every campaign of theirs: the last 7 days' numbers where they spent, and the paused ones too
     * (to switch back on) — running first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function allCampaigns(Client $client, CarbonImmutable $today): array
    {
        $rows = collect(AdPerformance::table($client, $today->subDays(7), $today))->keyBy('id');
        foreach ($client->meta_campaigns ?? [] as $id => $k) {
            if (! $rows->has((string) $id) && ! in_array($k['status'] ?? null, ['ARCHIVED', 'DELETED'], true)) {
                $rows[(string) $id] = ['id' => (string) $id, 'name' => $k['name'], 'status' => $k['status'] ?? null, 'daily_budget' => $k['daily_budget'] ?? null,
                    'end' => $k['end'] ?? null, 'currency' => strtoupper($client->currency ?: 'SGD'), 'spend' => 0,
                    ...AdPerformance::present($k['objective'] ?? null, null, 0, 0, 0), 'impressions' => 0, 'clicks' => 0, 'ctr' => null];
            }
        }

        return $rows->sortBy([fn ($a, $b) => ($b['status'] === 'ACTIVE') <=> ($a['status'] === 'ACTIVE'), fn ($a, $b) => $b['spend'] <=> $a['spend']])->values()->all();
    }

    /**
     * The big client's ad desk (Tasfia Engineering) at the top of the business dashboard:
     * money now, today's spend, and the last 7 days' campaigns — run from here.
     *
     * @return array<string, mixed>|null
     */
    private function adDesk(): ?array
    {
        $client = Airwallex::metaClient();
        if (! $client) {
            return null;
        }
        $today = CarbonImmutable::today();
        $currency = strtoupper($client->currency ?: 'SGD');
        $status = app(PrepaidBalance::class)->status($client);
        $balances = json_decode((string) Setting::get('airwallex_balances'), true) ?: [];

        return [
            'client' => $client->only('id', 'name', 'company'),
            'currency' => $currency,
            'balance' => $status['balance'],
            'days_left' => $status['days_left'],
            'daily' => $status['daily'],
            'airwallex' => $balances[$currency] ?? null,
            'today' => (int) MetaSpendDay::where('client_id', $client->id)->whereDate('date', $today)->sum('spend'),
            'meta_month' => -(int) AirwallexEntry::where('kind', 'meta')->where('client_id', $client->id)->where('occurred_at', '>=', $today->startOfMonth())->sum('amount'),
            'waiting' => ChargeRequest::where('client_id', $client->id)->where('status', 'pending')->first()?->only('id', 'amount'),
            'campaigns' => $this->allCampaigns($client, $today),
            'suggestions' => AdPerformance::suggestions($client),
            'advice' => AdAdvice::forOwner($client->company ?: $client->name),
        ];
    }
}
