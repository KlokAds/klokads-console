<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MetaSpendDay;
use App\Services\AdPerformance;
use App\Services\MetaAds;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** A business client's campaign (Tasfia's), run from the dashboard: on / pause, budget a day, end date — on Meta. */
class ClientCampaignController extends Controller
{
    /** The campaign in full — the same page as my own clients' campaigns. */
    public function show(Request $request, Client $client, string $campaign): Response
    {
        $known = $client->meta_campaigns[$campaign] ?? null;
        $days = MetaSpendDay::where('client_id', $client->id)->where('campaign_id', $campaign);
        abort_unless($known || (clone $days)->exists(), 404);

        return Inertia::render('Personal/AdClients/Campaign', AdPerformance::campaignDetail($days, $campaign, $known, (string) $request->input('period', 'last7'), strtoupper($client->currency ?: 'SGD')) + [
            'owner' => ['name' => $client->company ?: $client->name],
            'business' => true,
            'urls' => [
                'back' => route('ad-desk', ['tab' => 'campaigns']),
                'self' => route('clients.campaigns.show', [$client, $campaign]),
                'control' => route('clients.campaigns.control', [$client, $campaign]),
            ],
        ]);
    }

    public function control(Request $request, Client $client, string $campaign, MetaAds $meta): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume', 'budget', 'extend'])],
            'daily_budget' => ['required_if:action,budget', 'nullable', 'numeric', 'min:1', 'max:10000000'],
            'end' => ['required_if:action,extend', 'nullable', 'date', 'after_or_equal:today'],
        ]);
        $known = $client->meta_campaigns[$campaign] ?? null;
        abort_unless($known && $client->meta_ad_account_id, 404);
        $change = match ($data['action']) {
            'pause' => ['active' => false],
            'resume' => ['active' => true],
            'budget' => ['daily_budget' => (float) $data['daily_budget']],
            'extend' => ['new_end' => CarbonImmutable::parse($data['end'])->toDateString()],
        } + ['reason' => 'MH-Bot: '.($client->company ?: $client->name)];

        try {
            $limits = $meta->setLimits($client->meta_ad_account_id, $campaign, $change);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $all = $client->meta_campaigns;
        $all[$campaign] = array_merge($known, [
            'status' => $limits['active'] ? 'ACTIVE' : 'PAUSED',
            'daily_budget' => $limits['daily_budget'] ?? $known['daily_budget'] ?? null,
            'end' => $limits['end'] ?? $known['end'] ?? null,
        ]);
        $client->update(['meta_campaigns' => $all]);

        return back()->with('success', match ($data['action']) {
            'pause' => "“{$known['name']}” paused.",
            'resume' => "“{$known['name']}” is running again.",
            'budget' => "“{$known['name']}” budget is now {$data['daily_budget']} a day.",
            'extend' => "“{$known['name']}” now runs to ".CarbonImmutable::parse($data['end'])->format('j M Y').'.',
        });
    }
}
