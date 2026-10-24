<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The family guide: if something happens to me, where my money is and how to bring
 * the foreign money home to BDT. Opened by a secret link only; shows last 4 digits only.
 */
class GuideController extends Controller
{
    public static function token(): string
    {
        if (! Setting::get('guide_token')) {
            Setting::put('guide_token', Str::random(48));
        }

        return Setting::get('guide_token');
    }

    public function show(string $token): Response
    {
        abort_unless(hash_equals(self::token(), $token), 404);

        $accounts = Account::where('scope', 'personal')->where('archived', false)->where('kind', '!=', 'cash')->orderBy('currency')->orderBy('name')->get();
        $providers = collect(Catalog::PROVIDERS)->flatMap(fn ($list) => collect($list)->map(fn ($p) => $p[0]));

        return Inertia::render('Public/Guide', [
            'owner' => Setting::get('personal_name') ?: (auth()->user()?->name ?? null),
            'message' => Setting::get('guide_message'),
            'updated' => now()->toDateString(),
            'accounts' => $accounts->map(fn (Account $a) => [
                'name' => $a->name,
                'provider' => $a->provider && $a->provider !== 'other' ? $providers[$a->provider] ?? null : null,
                'kind' => $a->kind,
                'mfs' => in_array($a->provider, Catalog::MFS, true),
                'currency' => strtoupper($a->currency),
                'last4' => $a->last4,
                'balance' => Ledger::balance($a),
                'guide' => strtoupper($a->currency) === 'BDT' ? $a->guide : ($a->guide ?: (Catalog::WITHDRAW_GUIDES[$a->provider] ?? null)),
            ])->values(),
        ]);
    }

    /** My note on the guide, and a new link if the old one leaked. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['message' => ['nullable', 'string', 'max:3000'], 'new_link' => ['boolean']]);
        Setting::put('guide_message', $data['message'] ?? '');
        if ($data['new_link'] ?? false) {
            Setting::put('guide_token', Str::random(48));
        }

        return back()->with('success', ($data['new_link'] ?? false) ? 'New link made — the old one no longer opens.' : 'Guide saved.');
    }
}
