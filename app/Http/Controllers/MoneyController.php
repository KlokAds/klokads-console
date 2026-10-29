<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PersonalAccess;
use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Services\AutoEntries;
use App\Support\Catalog;
use App\Support\Ledger;
use App\Support\PayeeMemory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Money: bank accounts, wallets and cash (business and personal), what came in, what
 * went out and where, and conversions between currencies — month by month.
 */
class MoneyController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // This page is the business side's money; personal money lives on the personal Activity page.
        if ($request->input('scope') === 'personal') {
            PersonalAccess::guard($request);

            return redirect()->route('personal.activity', array_filter(['month' => $request->input('month'), 'q' => $request->input('q'), 'new' => $request->input('new')]));
        }
        $scope = 'business';
        $request->session()->put('mode', $scope);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();

        $allAccounts = Account::orderBy('archived')->orderBy('scope')->orderBy('name')->get();
        $ids = $allAccounts->where('scope', $scope)->pluck('id');

        $transactions = MoneyTransaction::with('account:id,name,currency,scope', 'toAccount:id,name,currency,scope,purpose')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->whereBetween('occurred_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->latest('occurred_on')->latest('id')
            ->get();

        return Inertia::render('Money/Index', [
            'scope' => $scope,
            'month' => $month,
            'base' => Ledger::base(),
            'accounts' => $allAccounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'scope', 'kind', 'purpose', 'provider', 'currency', 'opening_balance', 'opening_rate', 'notes', 'archived'),
                'balance' => $balance = Ledger::balance($a),
                'rate' => Ledger::rate($a),
                'base_value' => Ledger::value($a, $balance),
            ]),
            'transactions' => $transactions->map(fn (MoneyTransaction $t) => [
                ...$t->only('id', 'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'base_amount', 'category', 'counterparty', 'description', 'payout_id'),
                'occurred_on' => $t->occurred_on->toDateString(),
                'account' => $t->account,
                'to_account' => $t->toAccount,
                // Seen from this scope: money leaving it, arriving, or moving inside it.
                'direction' => Ledger::direction($t, $ids->all()),
                'fee_value' => $t->fee ? Ledger::value($t->account, $t->fee, $t->id) : 0,
            ]),
            'summary' => Ledger::summary($transactions, $ids->all()),
            'categories' => [
                'expense' => Catalog::options($scope === 'business' ? Catalog::EXPENSE_CATEGORIES : Catalog::PERSONAL_CATEGORIES),
                'income' => Catalog::options(Catalog::INCOME_CATEGORIES[$scope]),
                'loan' => Catalog::options(Catalog::LOAN_CATEGORIES), // labels only (entries come from Dena-paona)
            ],
            'kinds' => Catalog::options(Catalog::ACCOUNT_KINDS),
            'providers' => Catalog::PROVIDERS,
            'personalCurrencies' => Catalog::PERSONAL_CURRENCIES,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $request->input('scope') === 'personal' && PersonalAccess::guard($request);
        Account::create($this->accountData($request));

        return back()->with('success', 'Account added.');
    }

    /** "Match bank": book the gap so the account says what the bank's latest alert said. */
    public function matchBank(Request $request, Account $account): RedirectResponse
    {
        $account->scope === 'personal' && PersonalAccess::guard($request);
        $tx = app(AutoEntries::class)->matchBank($account);

        return back()->with('success', $tx ? 'Matched: '.$account->name.' now says what the bank says.' : 'Already the same as the bank.');
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        ($account->scope === 'personal' || $request->input('scope') === 'personal') && PersonalAccess::guard($request);
        $account->update($this->accountData($request, $account));

        return back()->with('success', 'Account updated.');
    }

    public function destroyAccount(Request $request, Account $account): RedirectResponse
    {
        $account->scope === 'personal' && PersonalAccess::guard($request);
        if ($account->outgoing()->exists() || $account->incoming()->exists()) {
            $account->update(['archived' => true]);

            return back()->with('success', "{$account->name} archived — its history is kept.");
        }

        $account->delete();

        return back()->with('success', 'Account removed.');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->guardAccounts($request, [$request->input('account_id'), $request->input('to_account_id')]);
        $tx = Ledger::save($this->transactionData($request));
        PayeeMemory::remember($tx);

        // Entries made offline are sent later by the app itself and only need a yes.
        if ($request->wantsJson()) {
            return response()->json(['id' => $tx->id], 201);
        }

        return back()->with('success', 'Saved.');
    }

    /** One month of entries for one side, as a spreadsheet (CSV). */
    public function export(Request $request): StreamedResponse
    {
        $scope = $request->input('scope') === 'personal' ? 'personal' : 'business';
        $scope === 'personal' && PersonalAccess::guard($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');
        $ids = Account::where('scope', $scope)->pluck('id');
        $labels = Catalog::EXPENSE_CATEGORIES + Catalog::PERSONAL_CATEGORIES + Catalog::INCOME_CATEGORIES['business'] + Catalog::INCOME_CATEGORIES['personal'];

        $rows = MoneyTransaction::with('account', 'toAccount')
            ->where(fn ($q) => $q->whereIn('account_id', $ids)->orWhereIn('to_account_id', $ids))
            ->where('occurred_on', 'like', "{$month}%")
            ->orderBy('occurred_on')->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows, $labels) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'From / account', 'To', 'Category', 'Paid to / from', 'Purpose', 'Amount', 'Currency', 'Fee', 'Arrived', 'Arrived currency']);
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->occurred_on->toDateString(),
                    ['income' => 'Money in', 'expense' => 'Spent', 'transfer' => 'Move / convert'][$t->kind],
                    $t->account->name,
                    $t->toAccount?->name,
                    $t->category ? ($labels[$t->category] ?? $t->category) : '',
                    $t->counterparty,
                    $t->description,
                    number_format($t->amount / 100, 2, '.', ''),
                    strtoupper($t->account->currency),
                    $t->fee ? number_format($t->fee / 100, 2, '.', '') : '',
                    $t->to_amount !== null && $t->toAccount ? number_format($t->to_amount / 100, 2, '.', '') : '',
                    $t->toAccount ? strtoupper($t->toAccount->currency) : '',
                ]);
            }
            fclose($out);
        }, "{$scope}-money-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    public function update(Request $request, MoneyTransaction $transaction): RedirectResponse
    {
        $this->guardAccounts($request, [$transaction->account_id, $transaction->to_account_id, $request->input('account_id'), $request->input('to_account_id')]);
        PayeeMemory::remember(Ledger::save($this->transactionData($request), $transaction));

        return back()->with('success', 'Updated.');
    }

    public function destroy(Request $request, MoneyTransaction $transaction): RedirectResponse
    {
        $this->guardAccounts($request, [$transaction->account_id, $transaction->to_account_id]);
        Ledger::delete($transaction);

        return back()->with('success', 'Deleted.');
    }

    /** Touching a personal account needs the owner (and PIN). */
    private function guardAccounts(Request $request, array $ids): void
    {
        if (Account::whereKey(array_filter($ids))->where('scope', 'personal')->exists()) {
            PersonalAccess::guard($request);
        }
    }

    /** @return array<string, mixed> */
    private function accountData(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::in(['business', 'personal'])],
            'kind' => ['required', Rule::in(array_keys(Catalog::ACCOUNT_KINDS))],
            'currency' => ['required', 'alpha', 'size:3', Rule::when($request->input('scope') === 'personal', [Rule::in(array_merge(Catalog::PERSONAL_CURRENCIES, array_map('strtolower', Catalog::PERSONAL_CURRENCIES)))])],
            'provider' => ['nullable', 'string', 'max:40'],
            'last4' => ['nullable', 'digits:4'],
            'app_profile' => ['nullable', Rule::in(['main', 'clone'])],
            'in_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'in_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'out_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'out_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'guide' => ['nullable', 'string', 'max:4000'],
            'purpose' => ['nullable', Rule::in(['spending', 'savings'])],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'archived' => ['boolean'],
        ], ['currency.in' => 'Personal accounts are BDT, SGD or USD.']);

        $data['currency'] = strtoupper($data['currency']);
        $data['opening_balance'] = $this->toCents($data['opening_balance'] ?? 0);
        foreach (['in', 'out'] as $side) {
            $data["{$side}_fee_pct"] = (float) ($data["{$side}_fee_pct"] ?? 0);
            $data["{$side}_fee_fixed"] = $this->toCents($data["{$side}_fee_fixed"] ?? 0);
        }
        $data['opening_rate'] = strtolower($data['currency']) === Ledger::base() ? null : ($data['opening_rate'] ?? null);

        if ($account && $account->currency !== $data['currency'] && ($account->outgoing()->exists() || $account->incoming()->exists())) {
            $data['currency'] = $account->currency; // history is in the old currency
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function transactionData(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'account_id' => ['required', 'exists:accounts,id'],
            'to_account_id' => ['nullable', 'required_if:kind,transfer', 'exists:accounts,id', 'different:account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'to_amount' => ['nullable', 'numeric', 'min:0.01'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:40'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'for_whom' => ['nullable', 'string', 'max:40'],
            'investment_id' => ['nullable', Rule::exists('accounts', 'id')->where('kind', 'investment')],
            'description' => ['nullable', 'string', 'max:500'],
            'occurred_on' => ['required', 'date'],
        ], ['to_account_id.different' => 'Pick a different account to move money to.']);

        $from = Account::find($data['account_id']);
        $to = $data['kind'] === 'transfer' ? Account::find($data['to_account_id']) : null;

        if ($to && $to->currency !== $from->currency && blank($data['to_amount'] ?? null)) {
            throw ValidationException::withMessages(['to_amount' => "How much {$to->currency} arrived in {$to->name}?"]);
        }

        return [
            ...$data,
            'to_account_id' => $to?->id,
            'amount' => $this->toCents($data['amount']),
            'fee' => $this->toCents($data['fee'] ?? 0),
            // Same currency: what leaves arrives. Different: what the other bank actually credited.
            'to_amount' => $to ? ($to->currency === $from->currency ? $this->toCents($data['amount']) : $this->toCents($data['to_amount'] ?? 0)) : null,
            'base_amount' => filled($data['base_amount'] ?? null) ? $this->toCents($data['base_amount']) : null,
            'category' => $data['kind'] === 'transfer' ? null : ($data['category'] ?? 'other'),
        ];
    }
}
