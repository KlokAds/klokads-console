<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My dollars (nSave Main and other USD accounts): what is there, every dollar that came in and from
 * where — my taka, the business's taka, or nSave Copy — at what rate, and my average buying rate.
 */
class DollarController extends Controller
{
    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $usd = Account::where('currency', 'USD')->where('archived', false)->orderByRaw("scope = 'personal' desc")->orderBy('name')->get();
        $chosen = $usd->firstWhere('id', $request->integer('account')) ?? $usd->firstWhere('name', 'nSave Main') ?? $usd->first();

        $in = $chosen ? MoneyTransaction::with('account')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', 'transfer')->where('to_account_id', $chosen->id))
                ->orWhere(fn ($q) => $q->where('kind', 'income')->where('account_id', $chosen->id)))
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(200)->get() : collect();

        $rows = $in->map(function (MoneyTransaction $t) {
            $isMove = $t->kind === 'transfer';
            $from = $isMove ? $t->account : null;
            $got = $isMove ? (int) $t->to_amount : $t->amount - $t->fee;
            $paidTaka = $isMove && $from && $from->currency === 'BDT' ? $t->amount + $t->fee : null;

            return [
                'id' => $t->id,
                'date' => $t->occurred_on?->toDateString(),
                'got' => $got,
                'source' => match (true) {
                    ! $isMove => 'in',
                    $from?->currency === 'BDT' && $from->scope === 'business' => 'business_taka',
                    $from?->currency === 'BDT' => 'my_taka',
                    $from?->scope === 'business' => 'business_dollars',
                    default => 'my_dollars',
                },
                'from' => $isMove ? $from?->name : ($t->counterparty ?: 'Money in'),
                'paid' => $isMove ? $t->amount + $t->fee : null,
                'paid_currency' => $from?->currency,
                'fee' => (int) $t->fee,
                'rate' => $paidTaka && $got > 0 ? round($paidTaka / $got, 2) : null,
                'note' => $t->description,
                'entry' => $t->only('id', 'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'category', 'counterparty', 'for_whom', 'description') + ['occurred_on' => $t->occurred_on?->toDateString()],
            ];
        });

        // What my dollars cost in taka: every dollar bought with taka, the fees included.
        $bought = $rows->whereNotNull('rate');
        $totals = $rows->groupBy('source')->map(fn ($list) => ['got' => (int) $list->sum('got'), 'paid' => (int) $list->sum('paid'), 'count' => $list->count()]);

        return Inertia::render('Personal/Dollars', [
            'accounts' => $usd->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name, 'scope' => $a->scope, 'balance' => Ledger::balance($a)])->values(),
            'account' => $chosen?->only('id', 'name', 'scope'),
            'rows' => $rows->values(),
            'totals' => $totals,
            'average' => $bought->sum('got') > 0 ? round($bought->sum(fn ($r) => $r['rate'] * $r['got']) / $bought->sum('got'), 2) : null,
            'bought' => ['got' => (int) $bought->sum('got'), 'paid' => (int) round($bought->sum(fn ($r) => $r['rate'] * $r['got']))],
        ]);
    }
}
