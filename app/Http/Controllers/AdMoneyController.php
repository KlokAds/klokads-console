<?php

namespace App\Http\Controllers;

use App\Models\AirwallexEntry;
use App\Models\ChargeRequest;
use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\MetaSpendDay;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\Airwallex;
use App\Services\PrepaidBalance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ad money in one place: the client's prepaid balance, Airwallex, the month's Meta spend
 * and charges, top-ups, and Stripe payouts on their way — plus every movement, newest first.
 */
class AdMoneyController extends Controller
{
    public function index(Request $request, PrepaidBalance $prepaid): Response
    {
        $client = $request->filled('client') ? Client::findOrFail($request->integer('client')) : Airwallex::metaClient();
        $months = collect(range(0, 5))->map(fn ($i) => CarbonImmutable::today()->startOfMonth()->subMonths($i));
        $month = $months->first(fn ($m) => $m->format('Y-m') === $request->input('month')) ?? $months->first();

        if (! $client) {
            return Inertia::render('AdMoney/Index', ['client' => null, 'months' => $this->monthOptions($months), 'month' => $month->format('Y-m')]);
        }

        $currency = strtoupper($client->currency ?: 'SGD');
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $status = $prepaid->status($client);
        $balances = json_decode((string) Setting::get('airwallex_balances'), true) ?: [];
        $spendDaily = (int) round(MetaSpendDay::where('client_id', $client->id)
            ->where('date', '>=', CarbonImmutable::today()->subDays(7)->toDateString())->where('date', '<', CarbonImmutable::today()->toDateString())->sum('spend') / 7);
        $onTheWay = Payout::whereIn('status', ['pending', 'in_transit'])->orderBy('arrival_at')->get();

        $entries = ClientBalanceEntry::where('client_id', $client->id)->whereBetween('date', [$from, $to]);
        $meta = AirwallexEntry::where('kind', 'meta')->where('client_id', $client->id)->whereBetween('occurred_at', [$month, $month->endOfMonth()]);
        $days = MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$from, $to])
            ->selectRaw('date, sum(spend) as spend')->groupBy('date')->pluck('spend', 'date');

        return Inertia::render('AdMoney/Index', [
            'client' => $client->only('id', 'name', 'company') + ['currency' => $currency, 'approve_charges' => $client->approve_charges, 'spend_billing' => $client->spend_billing],
            'clients' => Client::whereNotNull('meta_ad_account_id')->where('billing_mode', 'prepaid')->orderBy('company')->get(['id', 'name', 'company']),
            'months' => $this->monthOptions($months),
            'month' => $month->format('Y-m'),
            'now' => [
                'balance' => $status['balance'],
                'daily' => $status['daily'],
                'days_left' => $status['days_left'],
                'airwallex' => $balances[$currency] ?? 0,
                'airwallex_connected' => Airwallex::configured(),
                'airwallex_days' => $spendDaily > 0 ? round(($balances[$currency] ?? 0) / $spendDaily, 1) : null,
                'on_the_way' => (int) $onTheWay->where('currency', strtolower($currency))->sum('amount'),
                'next_arrival' => $onTheWay->first()?->arrival_at?->toIso8601String(),
                'waiting' => ChargeRequest::where('client_id', $client->id)->where('status', 'pending')->first()?->only('id', 'amount'),
            ],
            'totals' => [
                'spend' => (int) MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$from, $to])->sum('spend'),
                'commission' => (int) (clone $entries)->where('kind', 'spend')->sum('commission'),
                'topped_up' => (int) (clone $entries)->whereIn('kind', ['top_up', 'manual'])->where('amount', '>', 0)->sum('amount'),
                'meta_charged' => -(int) (clone $meta)->sum('amount'),
                'meta_charges' => (clone $meta)->count(),
                'payouts_in' => (int) AirwallexEntry::where('kind', 'deposit')->whereNotNull('payout_id')->whereBetween('occurred_at', [$month, $month->endOfMonth()])->sum('amount'),
            ],
            'days' => collect(range(0, $month->daysInMonth - 1))->map(fn ($d) => [
                'date' => $date = $month->addDays($d)->toDateString(),
                'spend' => (int) ($days[$date] ?? 0),
            ])->filter(fn ($d) => $d['date'] <= CarbonImmutable::today()->toDateString())->values(),
            'events' => $this->events($client, $month),
        ]);
    }

    /** Every movement of the month, newest first: balance, Airwallex, Stripe payouts, approvals. */
    private function events(Client $client, CarbonImmutable $month): array
    {
        $end = $month->endOfMonth();
        $events = collect();

        foreach (ClientBalanceEntry::where('client_id', $client->id)->whereBetween('date', [$month->toDateString(), $end->toDateString()])->get() as $e) {
            $events->push(['at' => $e->date->toDateString(), 'sort' => $e->date->format('Y-m-d').' 23:59', 'type' => $e->kind, 'amount' => $e->amount, 'where' => 'balance',
                'text' => match ($e->kind) {
                    'spend' => 'Meta spend '.number_format($e->spend / 100, 2).' + commission '.number_format($e->commission / 100, 2).' · '.$e->note,
                    'top_up' => 'Top-up charged to their card · '.$e->note,
                    default => $e->note ?: 'Adjustment',
                }]);
        }
        // Everything in Airwallex — not only this client's — so the account can be checked line by line.
        foreach (AirwallexEntry::whereBetween('occurred_at', [$month, $end])->get() as $e) {
            $events->push(['at' => $e->occurred_at->toIso8601String(), 'sort' => $e->occurred_at->format('Y-m-d H:i'), 'type' => $e->kind, 'amount' => $e->amount, 'currency' => $e->currency, 'where' => 'airwallex',
                'text' => match ($e->kind) {
                    'meta' => 'Meta charged the Airwallex card · '.$e->description,
                    'deposit' => ($e->payout_id ? 'Stripe payout arrived · ' : 'Money in · ').$e->description,
                    'conversion' => $e->amount >= 0 ? 'Converted into '.$e->currency : 'Converted out of '.$e->currency,
                    'payout' => (string) $e->description,
                    'fee' => 'Airwallex fee · '.$e->description,
                    'card' => 'Card payment · '.$e->description,
                    default => $e->description ?: 'Other movement',
                }]);
        }
        foreach (Payout::whereBetween('occurred_at', [$month, $end])->get() as $p) {
            $events->push(['at' => $p->occurred_at->toIso8601String(), 'sort' => $p->occurred_at->format('Y-m-d H:i'), 'type' => 'payout', 'amount' => $p->amount, 'currency' => strtoupper($p->currency), 'where' => 'stripe',
                'text' => 'Stripe payout '.$p->status.($p->arrival_at ? ' · arrives '.$p->arrival_at->format('j M') : '')]);
        }
        foreach (ChargeRequest::where('client_id', $client->id)->whereBetween('created_at', [$month, $end])->get() as $c) {
            $events->push(['at' => $c->created_at->toIso8601String(), 'sort' => $c->created_at->format('Y-m-d H:i'), 'type' => 'approval', 'amount' => $c->amount, 'where' => 'approval', 'id' => $c->id,
                'text' => 'Top-up drafted · '.$c->status]);
        }

        return $events->sortByDesc('sort')->take(80)->values()->all();
    }

    private function monthOptions($months): array
    {
        return $months->map(fn ($m) => ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')])->all();
    }
}
