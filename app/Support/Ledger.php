<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Expense;
use App\Models\MoneyTransaction;
use App\Models\Payout;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Balances and reporting-currency values for the Money page.
 *
 * Each foreign-currency account carries the average rate you actually paid to fill it
 * (reporting currency per unit, fees included), so a BDT salary paid from it counts at
 * what those taka really cost you in SGD.
 */
class Ledger
{
    public static function base(): string
    {
        return strtolower(Setting::get('currency', 'sgd'));
    }

    public static function isBase(Account $account): bool
    {
        return strtolower($account->currency) === self::base();
    }

    /** Current balance in the account's own currency (cents). */
    public static function balance(Account $account): int
    {
        $in = (int) MoneyTransaction::where('account_id', $account->id)->where('kind', 'income')->sum(DB::raw('amount - fee'));
        $out = (int) MoneyTransaction::where('account_id', $account->id)->whereIn('kind', ['expense', 'transfer'])->sum(DB::raw('amount + fee'));
        $moved = (int) MoneyTransaction::where('to_account_id', $account->id)->where('kind', 'transfer')->sum('to_amount');

        return $account->opening_balance + $in + $moved - $out;
    }

    /** Reporting currency per 1 unit of the account's currency: what filling it actually cost. */
    public static function rate(Account $account, ?int $exceptId = null): ?float
    {
        if (self::isBase($account)) {
            return 1.0;
        }

        $units = 0;
        $value = 0.0;

        if ($account->opening_balance > 0 && $account->opening_rate) {
            $units += $account->opening_balance;
            $value += $account->opening_balance * $account->opening_rate;
        }

        $fills = MoneyTransaction::query()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', 'transfer')->where('to_account_id', $account->id))
                ->orWhere(fn ($q) => $q->where('kind', 'income')->where('account_id', $account->id)))
            ->get(['kind', 'amount', 'fee', 'to_amount', 'base_amount']);

        foreach ($fills as $t) {
            $got = $t->kind === 'transfer' ? (int) $t->to_amount : $t->amount - $t->fee;
            if ($got > 0 && $t->base_amount > 0) {
                $units += $got;
                $value += $t->base_amount;
            }
        }

        return $units > 0 ? $value / $units : ($account->opening_rate ?: null);
    }

    /** Value of some of the account's money in the reporting currency (cents). */
    public static function value(Account $account, int $units, ?int $exceptId = null): int
    {
        $rate = self::rate($account, $exceptId);

        return $rate === null ? 0 : (int) round($units * $rate);
    }

    /**
     * Save a transaction: work out its reporting-currency value and keep the business
     * expense list (profit reports) in step with it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?MoneyTransaction $tx = null): MoneyTransaction
    {
        return DB::transaction(function () use ($data, $tx) {
            $tx ??= new MoneyTransaction;
            $tx->fill($data);
            $from = Account::findOrFail($tx->account_id);

            $tx->base_amount = match ($tx->kind) {
                // Money in: either it is in the reporting currency, or you said what it was worth.
                'income' => self::isBase($from) ? $tx->amount - $tx->fee : (int) ($data['base_amount'] ?? self::value($from, $tx->amount - $tx->fee, $tx->id)),
                // Spent: what those units cost you, fee included.
                'expense' => self::value($from, $tx->amount + $tx->fee, $tx->id),
                // Moved/converted: value of what was sent; the fee is its own expense, so it is
                // not baked into the receiving account's rate (no double counting later).
                default => self::value($from, $tx->amount, $tx->id),
            };

            if ($tx->kind === 'transfer' && $tx->to_amount === null) {
                $tx->to_amount = $tx->amount; // same currency
            }

            $tx->save();
            self::syncExpenses($tx->load('account', 'toAccount'));

            return $tx;
        });
    }

    public static function delete(MoneyTransaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            Expense::whereKey(array_filter([$tx->expense_id, $tx->fee_expense_id]))->delete();
            $tx->delete();
        });
    }

    /** Business spending from an account also appears under Expenses, so profit stays true. */
    private static function syncExpenses(MoneyTransaction $tx): void
    {
        $business = $tx->account->scope === 'business';
        $foreign = ! self::isBase($tx->account);
        $feeValue = $tx->fee > 0 ? self::value($tx->account, $tx->fee, $tx->id) : 0;

        // The spend itself (fee booked separately below).
        $spend = $business && $tx->kind === 'expense' ? [
            'category' => array_key_exists($tx->category, Catalog::EXPENSE_CATEGORIES) ? $tx->category : 'other',
            'vendor' => $tx->counterparty,
            'description' => $tx->description,
            'amount' => $tx->base_amount - $feeValue,
            'original_amount' => $foreign ? $tx->amount : null,
            'original_currency' => $foreign ? strtolower($tx->account->currency) : null,
            'paid_via' => 'bank_transfer',
            'reference' => "Money #{$tx->id} · {$tx->account->name}",
            'spent_on' => $tx->occurred_on,
        ] : null;

        // Bank / transfer fees on any business account movement.
        $fee = $business && $feeValue > 0 ? [
            'category' => 'bank_fees',
            'vendor' => $tx->account->name,
            'description' => match ($tx->kind) {
                'income' => 'Bank fee on deposit'.($tx->counterparty ? " — {$tx->counterparty}" : ''),
                'transfer' => 'Transfer / conversion fee'.($tx->toAccount ? " → {$tx->toAccount->name}" : ''),
                default => 'Bank fee'.($tx->counterparty ? " — {$tx->counterparty}" : ''),
            },
            'amount' => $feeValue,
            'original_amount' => $foreign ? $tx->fee : null,
            'original_currency' => $foreign ? strtolower($tx->account->currency) : null,
            'paid_via' => 'bank_transfer',
            'reference' => "Money #{$tx->id} · fee",
            'spent_on' => $tx->occurred_on,
        ] : null;

        $tx->expense_id = self::upsertExpense($tx->expense_id, $spend);
        $tx->fee_expense_id = self::upsertExpense($tx->fee_expense_id, $fee);
        $tx->saveQuietly();
    }

    /** @param  array<string, mixed>|null  $data */
    private static function upsertExpense(?int $id, ?array $data): ?int
    {
        $existing = $id ? Expense::find($id) : null;

        if (! $data) {
            $existing?->delete();

            return null;
        }

        if ($existing) {
            $existing->update($data);

            return $existing->id;
        }

        return Expense::create($data)->id;
    }

    // ---------------------------------------------------------------- reports

    /** @param  array<int, int>  $ids */
    public static function direction(MoneyTransaction $t, array $ids): string
    {
        $from = in_array($t->account_id, $ids, true);
        $to = $t->to_account_id && in_array($t->to_account_id, $ids, true);

        return match (true) {
            $t->kind === 'income' => 'in',
            $t->kind === 'expense' => 'out',
            $from && $to => 'move',
            $from => 'out',
            default => 'in',
        };
    }

    /**
     * Month totals in the reporting currency, as seen from one scope.
     *
     * @param  iterable<int, MoneyTransaction>  $transactions
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    public static function summary(iterable $transactions, array $ids): array
    {
        $in = $spent = $fees = $sentOut = $cameIn = 0;
        $byCategory = [];

        foreach ($transactions as $t) {
            $fee = $t->fee ? self::value($t->account, $t->fee, $t->id) : 0;
            $from = in_array($t->account_id, $ids, true);
            $to = $t->to_account_id && in_array($t->to_account_id, $ids, true);

            if ($t->kind === 'income') {
                $in += $t->base_amount;
                $fees += $fee;
            } elseif ($t->kind === 'expense') {
                $spent += $t->base_amount - $fee;
                $fees += $fee;
                $byCategory[$t->category ?: 'other'] = ($byCategory[$t->category ?: 'other'] ?? 0) + $t->base_amount - $fee;
            } else {
                if ($from) {
                    $fees += $fee;
                }
                if ($from && ! $to) {
                    $sentOut += $t->base_amount;
                } elseif ($to && ! $from) {
                    $cameIn += $t->base_amount;
                }
            }
        }

        if ($fees) {
            $byCategory['bank_fees'] = ($byCategory['bank_fees'] ?? 0) + $fees;
        }
        arsort($byCategory);

        return [
            'in' => $in,
            'spent' => $spent + $fees,
            'fees' => $fees,
            'sent_out' => $sentOut, // e.g. business → personal (owner draw)
            'came_in' => $cameIn,
            'net' => $in + $cameIn - $spent - $fees - $sentOut,
            'by_category' => $byCategory,
        ];
    }

    // ---------------------------------------------------------------- payouts

    public static function bankFeeRate(): float
    {
        return (float) Setting::get('bank_fee_rate', 0);
    }

    public static function payoutAccount(): ?Account
    {
        $id = Setting::get('payout_account_id');

        return $id ? Account::where('archived', false)->find($id) : null;
    }

    /** Bank's cut when a payout lands (e.g. 0.3%). */
    public static function payoutFee(int $amount): int
    {
        return (int) round($amount * self::bankFeeRate() / 100);
    }

    /** A paid live payout becomes money in the chosen bank account, minus the bank's fee. */
    public static function recordPayout(Payout $payout): ?MoneyTransaction
    {
        $account = self::payoutAccount();

        if (! $account || $payout->status !== 'paid' || ! $payout->livemode
            || strtolower($account->currency) !== strtolower($payout->currency)
            || MoneyTransaction::where('payout_id', $payout->id)->exists()) {
            return null;
        }

        return self::save([
            'kind' => 'income',
            'account_id' => $account->id,
            'amount' => $payout->amount,
            'fee' => self::payoutFee($payout->amount),
            'category' => 'stripe_payout',
            'counterparty' => 'Stripe',
            'description' => 'Stripe payout '.$payout->stripe_payout_id,
            'occurred_on' => ($payout->arrival_at ?? $payout->occurred_at)->toDateString(),
            'payout_id' => $payout->id,
        ]);
    }
}
