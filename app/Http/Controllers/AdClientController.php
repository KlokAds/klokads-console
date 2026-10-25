<?php

namespace App\Http\Controllers;

use App\Models\PersonalAdAssignment;
use App\Models\PersonalAdCharge;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use App\Services\KlokOneRate;
use App\Services\MetaAds;
use App\Services\PersonalAds;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * My own clients' ads (personal side): their ad accounts or campaigns, performance in each
 * account's currency, campaign controls, the ads bill ledger, and their private report link.
 */
class AdClientController extends Controller
{
    public function __construct(private PersonalAds $ads, private MetaAds $meta) {}

    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $month = CarbonImmutable::today()->startOfMonth();

        return Inertia::render('Personal/AdClients/Index', [
            'clients' => PersonalClient::whereHas('adAssignments')->orderBy('name')->get()->map(fn (PersonalClient $c) => [
                'id' => $c->id, 'name' => $c->name,
                'month' => PersonalAds::money($c, $month, CarbonImmutable::today()),
                'running' => collect($c->ad_campaigns ?? [])->where('status', 'ACTIVE')->count(),
                'my_card' => $c->adAssignments()->where('paid_by', 'me')->exists(),
                'due' => collect(PersonalAds::ledger($c)['totals'])->map(fn ($t) => $t['due'])->filter()->all(),
            ]),
            'others' => PersonalClient::whereDoesntHave('adAssignments')->orderBy('name')->get(['id', 'name']),
            'synced' => (bool) PersonalAdDay::exists(),
        ]);
    }

    public function show(Request $request, PersonalClient $client): Response
    {
        $request->session()->put('mode', 'personal');
        [$start, $end, $period] = $this->period($request);

        return Inertia::render('Personal/AdClients/Show', [
            'client' => $client->only('id', 'name', 'ad_bill', 'ad_fee_percent', 'ad_bill_basis', 'ad_meta_rate') + ['billed_until' => $client->ad_billed_until?->toDateString()],
            'rate' => KlokOneRate::current(),
            'range' => ['period' => $period, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => PersonalAds::period($start, $end)],
            'campaigns' => PersonalAds::table($client, $start, $end, $request->boolean('all')),
            'showAll' => $request->boolean('all'),
            'money' => PersonalAds::money($client, $start, $end),
            'ledger' => PersonalAds::ledger($client),
            'assignments' => $client->adAssignments()->orderBy('account_name')->get()
                ->map(fn ($a) => $a->only('id', 'account_id', 'account_name', 'currency', 'campaign_id', 'campaign_name', 'paid_by') + ['from' => $a->from_date->toDateString()]),
            'reportUrl' => route('ad-report.show', PersonalAds::token($client)),
            'accounts' => Inertia::defer(fn () => $this->accounts()),
        ]);
    }

    /** The ad accounts Klok Pilot has, for "add an account or campaigns". */
    private function accounts(): array
    {
        try {
            return collect($this->meta->adAccounts())->map(fn ($label, $id) => ['value' => $id, 'label' => $label])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** An account's campaigns, and who has each already (for the picker). */
    public function campaignsOf(Request $request): JsonResponse
    {
        $account = MetaAds::act((string) $request->query('account'));
        $taken = PersonalAdAssignment::with('client')->where('account_id', $account)->get();

        return response()->json(['campaigns' => collect($this->meta->campaigns($account))->map(fn ($c, $id) => [
            'id' => (string) $id, 'name' => $c['name'], 'status' => $c['status'], 'start' => $c['start'] ?? null,
            'owner' => $taken->firstWhere('campaign_id', (string) $id)?->client?->name,
        ])->values(), 'whole' => $taken->firstWhere('campaign_id', null)?->client?->name]);
    }

    public function assign(Request $request, PersonalClient $client): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:40'],
            'whole' => ['boolean'],
            'campaigns' => ['array', 'required_unless:whole,true'],
            'campaigns.*' => ['string', 'max:40'],
            'paid_by' => ['required', Rule::in(['me', 'client'])],
            'from' => ['required', 'date'],
        ]);
        $account = MetaAds::act($data['account_id']);
        try {
            $info = $this->meta->account($account);
            $names = $this->meta->campaigns($account);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        // A new ad client is billed the ads + my 20% commission, unless I set something else.
        if (($data['paid_by'] ?? null) === 'me' && ! $client->adAssignments()->where('paid_by', 'me')->exists() && (float) $client->ad_fee_percent === 0.0) {
            $client->update(['ad_fee_percent' => 20]);
        }
        $base = ['personal_client_id' => $client->id, 'account_name' => $info['name'], 'currency' => $info['currency'], 'paid_by' => $data['paid_by'], 'from_date' => $data['from']];

        $ids = ($data['whole'] ?? false) ? [null] : $data['campaigns'];
        foreach ($ids as $id) {
            $taken = PersonalAdAssignment::where('account_id', $account)->where('campaign_id', $id)->first();
            if ($taken && $taken->personal_client_id !== $client->id) {
                return back()->with('error', ($id ? '“'.($names[$id]['name'] ?? $id).'”' : 'That whole account').' already belongs to '.$taken->client->name.'.');
            }
            PersonalAdAssignment::updateOrCreate(['account_id' => $account, 'campaign_id' => $id], $base + ['campaign_name' => $id ? ($names[$id]['name'] ?? null) : null]);
        }
        try {
            $this->ads->sync(force: true);
        } catch (Throwable) {
            // the heartbeat tries again within the hour
        }

        return back()->with('success', 'Added — campaigns and spend are coming in.');
    }

    public function unassign(PersonalClient $client, PersonalAdAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->personal_client_id === $client->id, 404);
        $assignment->delete();

        return back()->with('success', 'Removed. What was already recorded stays.');
    }

    public function settings(Request $request, PersonalClient $client): RedirectResponse
    {
        $data = $request->validate([
            'ad_bill' => ['required', 'boolean'],
            'ad_fee_percent' => ['required', 'numeric', 'between:0,100'],
            'billed_until' => ['nullable', 'date', 'before_or_equal:today'],
            'ad_bill_basis' => ['required', Rule::in(['spend', 'charges'])],
            'ad_meta_rate' => ['required', 'numeric', 'between:50,500'],
        ]);
        $client->update(['ad_bill' => $data['ad_bill'], 'ad_fee_percent' => $data['ad_fee_percent'], 'ad_billed_until' => $data['billed_until'] ?? null,
            'ad_bill_basis' => $data['ad_bill_basis'], 'ad_meta_rate' => $data['ad_meta_rate']]);

        return back()->with('success', 'Saved.');
    }

    /** What Meta took from my card for this client (from the card's history), to bill once. */
    public function charge(Request $request, PersonalClient $client): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        PersonalAdCharge::create(['personal_client_id' => $client->id, 'date' => $data['date'], 'currency' => strtoupper($data['currency']),
            'amount' => $this->toCents($data['amount']), 'note' => $data['note'] ?? null]);

        return back()->with('success', 'Charge added — it goes on the next bill.');
    }

    public function removeCharge(PersonalClient $client, PersonalAdCharge $charge): RedirectResponse
    {
        abort_unless($charge->personal_client_id === $client->id && ! $charge->personal_ad_bill_id, 404);
        $charge->delete();

        return back()->with('success', 'Removed.');
    }

    /** Bill now: every unbilled day up to the date given (today by default). */
    public function bill(Request $request, PersonalClient $client): RedirectResponse
    {
        $data = $request->validate(['to' => ['nullable', 'date', 'before_or_equal:today']]);
        try {
            $bills = $this->ads->billUpTo($client, CarbonImmutable::parse($data['to'] ?? 'today')->startOfDay());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($bills ? 'success' : 'error', $bills ? count($bills).' bill'.(count($bills) > 1 ? 's' : '').' drafted in My invoices — check and send.' : 'Nothing to bill in those days.');
    }

    public function sync(): RedirectResponse
    {
        try {
            $this->ads->sync(force: true);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Updated from Meta.');
    }

    /** On / pause, budget a day, or run it to a later day — straight on Meta. */
    public function control(Request $request, PersonalClient $client, string $campaign): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume', 'budget', 'extend'])],
            'daily_budget' => ['required_if:action,budget', 'nullable', 'numeric', 'min:1', 'max:10000000'],
            'end' => ['required_if:action,extend', 'nullable', 'date', 'after_or_equal:today'],
        ]);
        $known = $client->ad_campaigns[$campaign] ?? null;
        abort_unless($known && isset($known['account']), 404);
        $change = match ($data['action']) {
            'pause' => ['active' => false],
            'resume' => ['active' => true],
            'budget' => ['daily_budget' => (float) $data['daily_budget']],
            'extend' => ['new_end' => CarbonImmutable::parse($data['end'])->toDateString()],
        } + ['reason' => "MH-Bot: {$client->name}"];

        try {
            $limits = $this->meta->setLimits($known['account'], $campaign, $change);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $all = $client->ad_campaigns;
        $all[$campaign] = array_merge($known, [
            'status' => $limits['active'] ? 'ACTIVE' : 'PAUSED',
            'daily_budget' => $limits['daily_budget'] ?? $known['daily_budget'] ?? null,
            'end' => $limits['end'] ?? $known['end'] ?? null,
        ]);
        $client->update(['ad_campaigns' => $all]);

        return back()->with('success', match ($data['action']) {
            'pause' => "“{$known['name']}” paused.",
            'resume' => "“{$known['name']}” is running again.",
            'budget' => "“{$known['name']}” budget is now {$data['daily_budget']} a day.",
            'extend' => "“{$known['name']}” now runs to ".CarbonImmutable::parse($data['end'])->format('j M Y').'.',
        });
    }

    /** The client's own page (no login, private link): their campaigns and results, nothing about my card. */
    public function report(Request $request, string $token): Response
    {
        $client = PersonalClient::where('ad_report_token', $token)->firstOrFail();
        [$start, $end, $period] = $this->period($request, 'last30');

        return Inertia::render('Personal/AdClients/Report', [
            'name' => $client->name,
            'range' => ['period' => $period, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => PersonalAds::period($start, $end)],
            'campaigns' => collect(PersonalAds::table($client, $start, $end))
                ->map(fn ($c) => collect($c)->only('id', 'name', 'status', 'goal', 'daily_budget', 'start', 'end', 'currency', 'spend', 'results', 'result_label', 'cost_per_result', 'per', 'impressions', 'clicks', 'ctr')->all())->values(),
            'totals' => collect(PersonalAds::money($client, $start, $end))->map(fn ($m) => ['spend' => $m['spend'], 'results' => $m['results']])->all(),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function period(Request $request, string $default = 'month'): array
    {
        $today = CarbonImmutable::today();
        $p = (string) $request->input('period', $default);

        return match (true) {
            $p === 'last7' => [$today->subDays(6), $today, $p],
            $p === 'last30' => [$today->subDays(29), $today, $p],
            $p === 'lastmonth' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), $p],
            $p === 'custom' && $request->filled(['from', 'to']) => [CarbonImmutable::parse($request->input('from'))->startOfDay(), CarbonImmutable::parse($request->input('to'))->startOfDay(), $p],
            default => [$today->startOfMonth(), $today, 'month'],
        };
    }
}
