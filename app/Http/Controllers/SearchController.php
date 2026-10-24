<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PersonalAccess;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Goal;
use App\Models\Invoice;
use App\Models\MoneyTransaction;
use App\Models\PaymentLink;
use App\Models\RecurringPlan;
use App\Support\Catalog;
use App\Support\Ledger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Global search (Ctrl+K): clients, invoices, plans, links, expenses, money, accounts, bills, goals. */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $any = fn (Builder $query, array $columns) => $query->where(function ($w) use ($columns, $like) {
            foreach ($columns as $column) {
                $w->orWhere($column, 'like', $like);
            }
        });
        $amount = fn (int $cents, string $currency) => strtoupper($currency).' '.number_format($cents / 100, 2);

        $groups = [
            'Clients' => $any(Client::query(), ['name', 'company', 'email', 'phone'])->limit(self::LIMIT)->get()->map(fn (Client $c) => [
                'title' => $c->company ?: $c->name,
                'sub' => trim(($c->company ? $c->name.' · ' : '').$c->email),
                'url' => route('clients.show', $c),
                'icon' => 'users',
            ]),
            'Invoices' => $any(Invoice::query()->with('client:id,name,company'), ['number', 'description'])
                ->orWhereHas('client', fn ($c) => $any($c, ['name', 'company']))
                ->latest('issued_at')->limit(self::LIMIT)->get()->map(fn (Invoice $i) => [
                    'title' => ($i->number ?: 'Draft').' · '.($i->client?->company ?: $i->client?->name ?: 'No client'),
                    'sub' => ucfirst($i->status).' · '.$amount((int) $i->total, $i->currency).($i->issued_at ? ' · '.$i->issued_at->format('j M Y') : ''),
                    'url' => route('invoices.document', $i),
                    'icon' => 'invoice',
                    'external' => true,
                ]),
            'Recurring plans' => $any(RecurringPlan::query()->with('client:id,name,company'), ['description'])->limit(self::LIMIT)->get()->map(fn (RecurringPlan $p) => [
                'title' => $p->description,
                'sub' => ($p->client?->company ?: $p->client?->name).' · '.$amount((int) $p->amount, $p->currency).' / '.$p->interval,
                'url' => route('recurring.index'),
                'icon' => 'repeat',
            ]),
            'Payment links' => $any(PaymentLink::query(), ['description', 'url'])->limit(self::LIMIT)->get()->map(fn (PaymentLink $l) => [
                'title' => $l->description,
                'sub' => $l->url,
                'url' => route('payment-links.index'),
                'icon' => 'link',
            ]),
            'Expenses' => $any(Expense::query(), ['vendor', 'description', 'reference'])->latest('spent_on')->limit(self::LIMIT)->get()->map(fn (Expense $e) => [
                'title' => $e->vendor ?: (Catalog::EXPENSE_CATEGORIES[$e->category] ?? $e->category),
                'sub' => $e->spent_on->format('j M Y').' · '.$amount((int) $e->amount, Ledger::base()).($e->description ? ' · '.$e->description : ''),
                'url' => route('expenses.index', ['month' => $e->spent_on->format('Y-m')]),
                'icon' => 'card',
            ]),
            'Money' => $any(MoneyTransaction::query()->with('account:id,name,currency,scope'), ['counterparty', 'description'])
                ->latest('occurred_on')->limit(self::LIMIT)->get()->map(fn (MoneyTransaction $t) => [
                    'title' => $t->counterparty ?: $t->description ?: ucfirst($t->kind),
                    'sub' => $t->occurred_on->format('j M Y').' · '.$t->account->name.' · '.$amount((int) $t->amount, $t->account->currency),
                    'url' => $t->account->scope === 'personal'
                        ? route('personal.activity', ['month' => $t->occurred_on->format('Y-m'), 'q' => $q])
                        : route('money.index', ['scope' => 'business', 'month' => $t->occurred_on->format('Y-m'), 'q' => $q]),
                    'icon' => 'wallet',
                    'scope' => $t->account->scope,
                ]),
            'Accounts' => $any(Account::query(), ['name', 'notes'])->limit(self::LIMIT)->get()->map(fn (Account $a) => [
                'title' => $a->name,
                'sub' => ucfirst($a->scope).' · '.strtoupper($a->currency).($a->archived ? ' · archived' : ''),
                'url' => $a->scope === 'personal' ? route('personal.activity', ['tab' => 'accounts']) : route('money.index', ['scope' => 'business']),
                'icon' => 'bank',
                'scope' => $a->scope,
            ]),
            'Bills & goals' => $any(Bill::query(), ['name', 'counterparty'])->limit(self::LIMIT)->get()->map(fn (Bill $b) => [
                'title' => $b->name,
                'sub' => 'Monthly bill · day '.$b->day,
                'url' => route('personal.plan'),
                'icon' => 'clock',
                'scope' => 'personal',
            ])->concat($any(Goal::query(), ['name'])->limit(self::LIMIT)->get()->map(fn (Goal $g) => [
                'title' => $g->name,
                'sub' => 'Savings goal',
                'url' => route('personal.plan'),
                'icon' => 'shield',
                'scope' => 'personal',
            ])),
        ];

        if (! PersonalAccess::allowed($request)) {
            $groups['Money'] = $groups['Money']->reject(fn ($i) => $i['scope'] === 'personal');
            $groups['Accounts'] = $groups['Accounts']->reject(fn ($i) => $i['scope'] === 'personal');
            unset($groups['Bills & goals']);
        }

        return response()->json([
            'groups' => collect($groups)->filter(fn ($items) => $items->isNotEmpty())
                ->map(fn ($items, $label) => ['label' => $label, 'items' => $items->values()])->values(),
        ]);
    }
}
