<?php

namespace App\Http\Controllers;

use App\Models\AirwallexEntry;
use App\Models\ChargeRequest;
use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Services\AdAdvice;
use App\Services\AdPerformance;
use App\Services\Airwallex;
use App\Services\PrepaidBalance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The big client's ad desk (Tasfia Engineering), complete in one place: how it is going, every
 * campaign (each opening in full), and the money — their balance, top-ups, Airwallex, Meta's charges.
 */
class AdDeskController extends Controller
{
    public function index(Request $request): Response
    {
        $client = $request->filled('client') ? Client::findOrFail($request->integer('client')) : Airwallex::metaClient();
        if (! $client) {
            return Inertia::render('AdDesk/Index', ['client' => null]);
        }
        $today = CarbonImmutable::today();
        $period = (string) $request->input('period', 'month');
        [$start, $end] = match ($period) {
            'last7' => [$today->subDays(6), $today],
            'last30' => [$today->subDays(29), $today],
            'lastmonth' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$today->startOfMonth(), $today],
        };
        $currency = strtoupper($client->currency ?: 'SGD');
        $status = app(PrepaidBalance::class)->status($client);
        $balances = json_decode((string) Setting::get('airwallex_balances'), true) ?: [];
        $spent = MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        return Inertia::render('AdDesk/Index', [
            'client' => $client->only('id', 'name', 'company') + ['approve_charges' => $client->approve_charges, 'spend_billing' => $client->spend_billing],
            'currency' => $currency,
            'tab' => in_array($request->query('tab'), ['overview', 'campaigns', 'money'], true) ? $request->query('tab') : 'overview',
            'range' => ['period' => in_array($period, ['last7', 'last30', 'lastmonth'], true) ? $period : 'month', 'label' => $start->format('j M').' – '.$end->format('j M Y')],
            'now' => [
                'balance' => $status['balance'], 'days_left' => $status['days_left'], 'daily' => $status['daily'],
                'today' => (int) MetaSpendDay::where('client_id', $client->id)->whereDate('date', $today)->sum('spend'),
                'airwallex' => $balances[$currency] ?? null,
                'waiting' => ChargeRequest::where('client_id', $client->id)->where('status', 'pending')->first()?->only('id', 'amount'),
            ],
            'spent' => ['spend' => (int) (clone $spent)->sum('spend'), 'results' => (int) (clone $spent)->sum('results'), 'clicks' => (int) (clone $spent)->sum('clicks')],
            'campaigns' => $this->campaigns($client, $start, $end),
            'advice' => AdAdvice::forOwner($client->company ?: $client->name),
            'suggestions' => AdPerformance::suggestions($client),
            'money' => [
                'meta_charged' => -(int) AirwallexEntry::where('kind', 'meta')->where('client_id', $client->id)->whereBetween('occurred_at', [$start, $end->endOfDay()])->sum('amount'),
                'topped_up' => (int) ClientBalanceEntry::where('client_id', $client->id)->whereIn('kind', ['top_up', 'manual'])->where('amount', '>', 0)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->sum('amount'),
                'entries' => ClientBalanceEntry::where('client_id', $client->id)->latest('date')->latest('id')->limit(20)->get()
                    ->map(fn ($e) => $e->only('id', 'kind', 'amount', 'spend', 'commission', 'note') + ['date' => $e->date->toDateString()]),
            ],
        ]);
    }

    /** Every campaign: the period's numbers where it spent, and the quiet ones too (to switch on). */
    private function campaigns(Client $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = collect(AdPerformance::table($client, $start, $end))->keyBy('id');
        $last = MetaSpendDay::where('client_id', $client->id)->where('spend', '>', 0)->selectRaw('campaign_id, max(date) as last')->groupBy('campaign_id')->pluck('last', 'campaign_id');
        foreach ($client->meta_campaigns ?? [] as $id => $k) {
            if (! $rows->has((string) $id) && ! in_array($k['status'] ?? null, ['ARCHIVED', 'DELETED'], true)) {
                $rows[(string) $id] = ['id' => (string) $id, 'name' => $k['name'], 'status' => $k['status'] ?? null, 'daily_budget' => $k['daily_budget'] ?? null,
                    'end' => $k['end'] ?? null, 'currency' => strtoupper($client->currency ?: 'SGD'), 'spend' => 0,
                    ...AdPerformance::present($k['objective'] ?? null, null, 0, 0, 0), 'impressions' => 0, 'clicks' => 0, 'ctr' => null];
            }
        }

        return $rows->map(fn ($r) => $r + ['last_spend' => isset($last[$r['id']]) ? substr((string) $last[$r['id']], 0, 10) : null])->values()->all();
    }
}
