<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InboxMessage;
use App\Models\MoneyTransaction;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\InboxRules;
use App\Support\Ledger;
use App\Support\PayeeMemory;
use App\Support\TransactionParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bank / wallet SMS and email alerts → money entries.
 * Clear ones are added on their own; money in that matches one of my invoices pays it;
 * anything unsure waits in the inbox for a tap.
 */
class AutoEntries
{
    /** Shop / payee words → personal spending category. */
    private const GUESS = [
        'food' => ['fairprice', 'ntuc', 'cold storage', 'giant', 'sheng siong', 'shwapno', 'meena bazar', 'agora', 'unimart', 'foodpanda', 'grabfood', 'restaurant', 'cafe', 'kfc', 'mcdonald'],
        'transport' => ['grab', 'gojek', 'comfort', 'uber', 'pathao', 'obhai', 'simplygo', 'ez-link', 'mrt', 'smrt', 'fuel', 'petrol', 'shell', 'esso'],
        'bills' => ['singtel', 'starhub', 'm1 ', 'circles', 'sp group', 'desco', 'dpdc', 'titas', 'wasa', 'grameenphone', 'robi', 'banglalink', 'teletalk', 'airtel', 'btcl', 'bangladesh telecommunications', 'recharge', 'internet'],
        'shopping' => ['shopee', 'lazada', 'daraz', 'amazon', 'uniqlo', 'aarong'],
        'health' => ['pharmacy', 'guardian', 'watsons', 'clinic', 'hospital', 'lazz'],
        'entertainment' => ['netflix', 'spotify', 'youtube', 'cinema', 'gv ', 'shaw'],
        'family' => ['ammu', 'abbu', 'family'],
    ];

    public function __construct(private PersonalInvoicing $invoicing, private Envelopes $envelopes, private Notify $notify) {}

    public static function token(): string
    {
        if (! Setting::get('inbox_token')) {
            Setting::put('inbox_token', Str::random(40));
        }

        return Setting::get('inbox_token');
    }

    /** Store an alert once and turn it into an entry if possible. */
    public function ingest(string $source, ?string $sender, string $body, ?CarbonImmutable $receivedAt = null, string $profile = 'main'): InboxMessage
    {
        $body = trim(mb_substr($body, 0, 5000));
        $parsed = InboxRules::ignored($body) ? null : TransactionParser::parse($sender, $body);
        if ($parsed) {
            $parsed['profile'] = $profile;
        }
        // The wallet's own transaction ID identifies a payment even if the alert arrives twice (SMS + email).
        $hash = hash('sha256', $parsed['reference'] ?? null ? ($parsed['provider'] ?? '').'|'.$parsed['reference'] : $source.'|'.$profile.'|'.$sender.'|'.$body);

        $existing = InboxMessage::firstWhere('hash', $hash);
        if ($existing) {
            return $existing;
        }

        $message = InboxMessage::create([
            'source' => $source, 'sender' => $sender ? mb_substr($sender, 0, 190) : null, 'body' => $body, 'hash' => $hash,
            'received_at' => $receivedAt ?? now(), 'parsed' => $parsed, 'status' => $parsed ? 'new' : 'skipped',
        ]);

        return $parsed ? $this->process($message) : $message;
    }

    public function process(InboxMessage $message, ?int $accountId = null, ?string $category = null): InboxMessage
    {
        $p = $message->parsed;
        $account = $accountId ? Account::find($accountId) : $this->account($p);
        if (! $account) {
            $message->update(['status' => 'review', 'note' => $p['provider'] ? 'No matching '.strtoupper($p['currency']).' account for '.$p['provider'] : 'Which account is this?']);
            $this->notify->send('info', '👀 '.($p['direction'] === 'in' ? 'Money in' : 'Money out').' — which account?',
                Notify::money($p['amount'], $p['currency']).' · '.($p['provider'] ? ucfirst($p['provider']) : ($message->sender ?: $message->source)).(($p['last4'] ?? null) ? " ••{$p['last4']}" : '').' — tap to choose the account.',
                route('personal.inbox.index'), 'klok-review');

            return $message;
        }
        $this->rememberBankBalance($account, $p + ['at' => $message->received_at ?? now()]);

        $date = ($message->received_at ?? now())->toDateString();
        // UCB's "NPSB CHARGE" SMS says ৳8.70; the bank also takes 15% VAT on it (৳1.30) with no SMS: ৳10 in all.
        if (self::isVatLessCharge($account, $message->body)) {
            $p['amount'] = (int) round($p['amount'] * 1.15, -2);
            $p['counterparty'] = 'NPSB fee (incl. VAT)';
            $category ??= $p['direction'] === 'out' ? 'bank_fees' : null;
        }
        if ($this->alreadyRecorded($account, $p, $date, $message->source)) {
            $message->update(['status' => 'ignored', 'account_id' => $account->id, 'note' => 'Already recorded']);

            return $message;
        }

        try {
            // Money in that pays one of my invoices: record it against the invoice.
            // Never my own money coming back (a reversal, or the same amount that just left this account).
            if ($p['direction'] === 'in' && ! $category && ! self::isReversal($message->body) && ! $this->justLeft($account, $p['amount'], $date)
                && ($invoice = $this->invoiceFor($p, $message->body, $account))) {
                $payment = $this->invoicing->recordPayment($invoice, min($p['amount'], $invoice->due()), $date, $p['provider'] ?? 'other', $p['reference'], $account->id, $message->source);
                $message->update(['status' => 'matched', 'account_id' => $account->id, 'personal_invoice_id' => $invoice->id, 'money_transaction_id' => $payment->money_transaction_id, 'note' => "Paid {$invoice->number}"]);

                return $message;
            }

            // Money out that is this month's envelope hand-out (e.g. Songsar from bKash).
            if ($p['direction'] === 'out' && ! $category && ($month = $this->envelopes->claim($account, $p['amount'], $p['fee'] ?? 0, $date, ucfirst($message->source).($p['reference'] ? " · {$p['reference']}" : '')))) {
                $message->update(['status' => 'added', 'account_id' => $account->id, 'money_transaction_id' => $month->money_transaction_id, 'note' => 'Envelope: '.$month->envelope->name]);
                $this->tellEntry($account, $p, '→ '.$month->envelope->name.' envelope');

                return $message;
            }

            // Meta took it from my card (FACEBK*): ad spend — business "Meta Ads", personal "Client ads".
            // The same charge already booked from Meta's own log (a client's charge) is matched, not added twice.
            if ($p['direction'] === 'out' && self::isMeta($message->body)) {
                $category ??= $account->scope === 'business' ? 'meta_ads' : 'client_ads';
                $p['counterparty'] = 'Meta';
                if ($booked = self::bookedMetaCharge($account, $p['amount'], $date)) {
                    Ledger::save(['amount' => $p['amount']], $booked); // the card's own amount is the exact one
                    $message->update(['status' => 'matched', 'account_id' => $account->id, 'money_transaction_id' => $booked->id, 'note' => 'Meta charge already on record']);

                    return $message;
                }
            }

            // Cashback and reversals: say what they are.
            if ($p['direction'] === 'in' && ! $p['counterparty']) {
                $p['counterparty'] = match (true) {
                    self::isReversal($message->body) => 'Reversal',
                    (bool) preg_match('/\bcashback\b/i', $message->body) => 'Cashback',
                    default => null,
                };
            }

            $tx = Ledger::save([
                'kind' => $p['direction'] === 'in' ? 'income' : 'expense',
                'account_id' => $account->id,
                'amount' => $p['amount'],
                'fee' => $p['fee'] ?? 0,
                // What I chose for this payee before; otherwise business "other" or a guessed personal category.
                'category' => $category ?? PayeeMemory::category($account->scope, $p['counterparty'])
                    ?? ($p['direction'] === 'in' ? (self::isInterest($message->body) ? 'interest' : 'other_income') : ($account->scope === 'business' ? 'other' : $this->guess($p['counterparty'].' '.$message->body))),
                'counterparty' => $p['counterparty'],
                'description' => 'Auto · '.ucfirst($p['provider'] ?? $message->source).' '.$message->source.($p['reference'] ? " · {$p['reference']}" : ''),
                'occurred_on' => $date,
            ]);
            $message->update(['status' => 'added', 'account_id' => $account->id, 'money_transaction_id' => $tx->id, 'note' => null]);
            if (! $this->pairOwnTransfer($message, $tx)) {
                $this->tellEntry($account, $p, null, $tx->id);
            }
        } catch (Throwable $e) {
            $message->update(['status' => 'review', 'note' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $message;
    }

    public static function isReversal(string $text): bool
    {
        return (bool) preg_match('/\brevers(?:al|ed)\b/i', $text);
    }

    /** A bank charge whose VAT the bank takes without its own SMS (UCB's NPSB charge). */
    public static function isVatLessCharge(Account $account, string $text): bool
    {
        return $account->provider === 'ucb' && (bool) preg_match('/\bnpsb charge\b/i', $text);
    }

    /** The same amount went out of this account in the last two days (this is it coming back). */
    private function justLeft(Account $account, int $amount, string $date): bool
    {
        $day = CarbonImmutable::parse($date);

        return MoneyTransaction::where('account_id', $account->id)->whereIn('kind', ['expense', 'transfer'])->where('amount', $amount)
            ->whereBetween('occurred_on', [$day->subDays(2)->toDateString(), $day->toDateString()])->exists();
    }

    /**
     * The books against the bank's own word (the balance in its latest SMS), once the alerts have settled:
     * a small gap (a fee or VAT with no SMS) is booked as a bank charge; a bigger one is told to me once.
     */
    public function reconcile(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $fixed = 0;
        foreach (Account::where('archived', false)->whereNotNull('bank_balance')->whereBetween('bank_balance_at', [$now->subDays(3), $now->subMinutes(10)])->get() as $account) {
            // Something booked after the bank's word: that word is old now.
            if (MoneyTransaction::where(fn ($q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id))->where('created_at', '>', $account->bank_balance_at)->exists()) {
                continue;
            }
            $gap = $account->bank_balance - Ledger::balance($account);
            if ($gap === 0) {
                continue;
            }
            if (abs($gap) <= self::SMALL_GAP) {
                $this->matchBank($account, $account->kind === 'wallet' ? 'No SMS for it (a recharge or fee)' : 'Bank charge / VAT (no SMS)');
                $fixed++;

                continue;
            }
            $this->notify->once("bank-gap:{$account->id}:{$account->bank_balance_at->timestamp}", 'info',
                '⚖️ '.$account->name.($account->last4 ? " ••{$account->last4}" : '').' is '.Notify::money(abs($gap), $account->currency).($gap < 0 ? ' more' : ' less').' than the bank says',
                'Bank says '.Notify::money($account->bank_balance, $account->currency).', books say '.Notify::money(Ledger::balance($account), $account->currency).'. Tap to check — or match it in one tap.',
                route('personal.activity', ['account' => $account->id]));
        }

        return $fixed;
    }

    /** Gaps up to this (cents) are a fee or VAT the bank sent no SMS for. */
    public const SMALL_GAP = 2000;

    /** Book the difference so the books say what the bank says. */
    public function matchBank(Account $account, string $why = 'Matched to the bank\'s balance'): ?MoneyTransaction
    {
        $gap = (int) $account->bank_balance - Ledger::balance($account);
        if ($account->bank_balance === null || $gap === 0) {
            return null;
        }

        return Ledger::save([
            'kind' => $gap > 0 ? 'income' : 'expense', 'account_id' => $account->id, 'amount' => abs($gap),
            'category' => $gap > 0 ? 'other_income' : ($account->scope === 'business' || abs($gap) <= self::SMALL_GAP ? 'bank_fees' : 'other'),
            'counterparty' => $account->name, 'description' => $why, 'occurred_on' => now()->toDateString(),
        ]);
    }

    public static function isMeta(string $text): bool
    {
        return (bool) preg_match('/\bfacebk\b|facebook ads|meta platforms/i', $text);
    }

    /**
     * A Meta charge on this account already on record from the other side (Meta's log, or the card's
     * message), within a few days and about the same amount (taka ÷ Meta's rate is close, not exact).
     */
    public static function bookedMetaCharge(Account $account, int $amount, string $date, bool $fromMetaLog = true): ?MoneyTransaction
    {
        $day = CarbonImmutable::parse($date);
        $linked = InboxMessage::whereNotNull('money_transaction_id')->pluck('money_transaction_id');

        return MoneyTransaction::where('account_id', $account->id)->where('kind', 'expense')->where('counterparty', 'Meta')
            ->whereBetween('occurred_on', [$day->subDays(3)->toDateString(), $day->addDays(3)->toDateString()])
            ->whereBetween('amount', [(int) floor($amount * 0.94), (int) ceil($amount * 1.06)])
            ->when($fromMetaLog, fn ($q) => $q->whereNotIn('id', $linked), fn ($q) => $q->whereIn('id', $linked))
            ->orderByRaw('abs(amount - ?)', [$amount])->first();
    }

    /** Undo an auto entry (and its invoice payment). */
    public function undo(InboxMessage $message): void
    {
        if ($message->personal_invoice_id) {
            $payment = PersonalInvoicePayment::firstWhere('money_transaction_id', $message->money_transaction_id);
            $payment ? $this->invoicing->removePayment($payment) : null;
        } elseif ($message->money_transaction_id && ($tx = MoneyTransaction::find($message->money_transaction_id))) {
            Ledger::delete($tx);
        }
        $message->update(['status' => 'review', 'money_transaction_id' => null, 'personal_invoice_id' => null, 'note' => 'Undone']);
    }

    /**
     * Which of my accounts: the last 4 digits first (they belong to one account),
     * then the bank / wallet and currency, with the digits picking between several.
     */
    private function account(array $p): ?Account
    {
        $live = Account::where('archived', false)->where('kind', '!=', 'investment')->whereRaw('upper(currency) = ?', [strtoupper($p['currency'])])->orderByRaw("scope = 'personal' desc")->get();
        $digits = $p['last4'] ?? null;

        if ($digits) {
            $byLast4 = $live->filter(fn ($a) => $a->last4 && str_ends_with($a->last4, $digits) && (! $p['provider'] || in_array($a->provider, [null, '', 'other'], true) || $a->provider === $p['provider']));
            if ($byLast4->count() === 1) {
                return $byLast4->first();
            }
        }

        if (! $p['provider']) {
            return null;
        }

        $candidates = $live->where('provider', $p['provider'])->values();
        if ($candidates->count() > 1 && $digits) {
            $byDigits = $candidates->filter(fn ($a) => str_contains($a->last4.' '.$a->name.' '.$a->notes, $digits));
            if ($byDigits->count() === 1) {
                return $byDigits->first();
            }
        }
        if ($candidates->count() <= 1) {
            return $candidates->first();
        }

        // Two accounts in the same wallet app (main app + the phone's App Clone): the copy decides.
        $profile = $p['profile'] ?? 'main';
        $byCopy = $candidates->filter(fn ($a) => ($a->app_profile ?: 'main') === $profile);
        if ($byCopy->count() === 1) {
            return $byCopy->first();
        }

        // The balance the alert reports fits only one of them.
        if (($p['balance'] ?? null) !== null) {
            $change = $p['direction'] === 'in' ? $p['amount'] - ($p['fee'] ?? 0) : -($p['amount'] + ($p['fee'] ?? 0));
            $byBalance = $candidates->filter(fn ($a) => Ledger::balance($a) + $change === $p['balance']);
            if ($byBalance->count() === 1) {
                return $byBalance->first();
            }
        }

        // What I chose last time for this bank / wallet (and app copy).
        $learned = json_decode((string) Setting::get('inbox_learned'), true)[$p['provider'].'|'.$profile] ?? null;

        return $candidates->firstWhere('id', $learned);
    }

    /** Remembers which account I picked for a bank / wallet (and app copy) that had several. */
    public static function learn(?array $parsed, int $accountId): void
    {
        if (! ($parsed['provider'] ?? null)) {
            return;
        }
        $map = json_decode((string) Setting::get('inbox_learned'), true) ?: [];
        $map[$parsed['provider'].'|'.($parsed['profile'] ?? 'main')] = $accountId;
        Setting::put('inbox_learned', json_encode($map));
    }

    /**
     * Money moved between two of my own accounts arrives as two alerts (a debit here, a credit
     * there). When both are in, they become one transfer — neither spending nor income.
     */
    private function pairOwnTransfer(InboxMessage $message, MoneyTransaction $tx): ?MoneyTransaction
    {
        $tx->loadMissing('account');
        // What arrives can be a little less than what left (the wallet's fee): up to 6% + 5.
        [$low, $high] = $tx->kind === 'income'
            ? [$tx->amount, (int) ceil($tx->amount * 1.06) + 500]
            : [max(0, (int) floor($tx->amount * 0.94) - 500), $tx->amount + $tx->fee];
        $candidates = MoneyTransaction::with('account')
            ->where('id', '!=', $tx->id)->where('account_id', '!=', $tx->account_id)
            ->where('kind', $tx->kind === 'income' ? 'expense' : 'income')
            ->whereBetween('amount', [$low, $high])->whereNull('to_account_id')
            ->where('description', 'like', 'Auto ·%')
            ->whereNotIn('category', ['work', 'envelope', ...array_keys(Catalog::LOAN_CATEGORIES)])
            ->whereBetween('occurred_on', [$tx->occurred_on->copy()->subDay()->toDateString(), $tx->occurred_on->copy()->addDay()->toDateString().' 23:59:59'])
            ->latest('id')->get();
        // A different amount only counts when a wallet that keeps fees is in it; bank to bank must match.
        $keepsFees = fn (Account $a) => in_array($a->provider, ['nsave', 'rizon', 'redotpay', 'binance', 'elevatepay', 'worldfirst', 'airwallex', 'payoneer', 'wise'], true)
            || $a->in_fee_pct > 0 || $a->in_fee_fixed > 0 || $a->out_fee_pct > 0 || $a->out_fee_fixed > 0;
        $other = $candidates->filter(fn (MoneyTransaction $t) => strtoupper($t->account->currency) === strtoupper($tx->account->currency))
            ->filter(fn (MoneyTransaction $t) => $t->amount === $tx->amount || $keepsFees($t->account) || $keepsFees($tx->account))
            ->sortBy(fn (MoneyTransaction $t) => abs($t->amount - $tx->amount))->first();
        if (! $other) {
            return $this->pairConversion($tx);
        }

        [$out, $in] = $tx->kind === 'expense' ? [$tx, $other] : [$other, $tx];
        $transfer = DB::transaction(function () use ($out, $in) {
            // What arrived moved; whatever went missing on the way is the fee.
            $transfer = Ledger::save([
                'kind' => 'transfer', 'account_id' => $out->account_id, 'to_account_id' => $in->account_id,
                'amount' => $in->amount, 'to_amount' => $in->amount, 'fee' => $out->amount + $out->fee - $in->amount,
                'description' => 'Auto · own transfer', 'occurred_on' => $out->occurred_on->toDateString(),
            ]);
            InboxMessage::whereIn('money_transaction_id', [$out->id, $in->id])
                ->update(['money_transaction_id' => $transfer->id, 'note' => "Own transfer {$out->account->name} → {$in->account->name}"]);
            Ledger::delete($out);
            Ledger::delete($in);

            return $transfer;
        });

        $name = fn (Account $a) => $a->name.($a->last4 && ! str_contains($a->name, $a->last4) ? " ••{$a->last4}" : '');
        $fee = $transfer->fee ? ' · fee '.Notify::money($transfer->fee, $out->account->currency) : '';
        $this->notify->send('info', '🔁 '.$name($out->account).' → '.$name($in->account).' '.Notify::money($in->amount, $in->account->currency).$fee,
            'Moved between your own accounts — not spending, not income.', route('personal.activity'), 'klok-money');

        return $transfer;
    }

    /**
     * USDT sold on Binance / RedotPay and the taka that arrives for it (bKash, bank) within a few
     * hours: one conversion, with the rate I got — not a dollar expense plus taka income.
     */
    private function pairConversion(MoneyTransaction $tx): ?MoneyTransaction
    {
        $usd = fn (MoneyTransaction $t) => strtoupper($t->account->currency) === 'USD' && in_array($t->account->provider, ['binance', 'redotpay'], true);
        $bdt = fn (MoneyTransaction $t) => strtoupper($t->account->currency) === 'BDT';
        if (! ($tx->kind === 'expense' ? $usd($tx) : ($tx->kind === 'income' && $bdt($tx)))) {
            return null;
        }

        $other = MoneyTransaction::with('account')->where('id', '!=', $tx->id)->whereNull('to_account_id')
            ->where('kind', $tx->kind === 'expense' ? 'income' : 'expense')->where('description', 'like', 'Auto ·%')
            ->whereNotIn('category', ['work', 'envelope', 'interest', ...array_keys(Catalog::LOAN_CATEGORIES)])
            ->where('created_at', '>=', now()->subHours(6))->latest('id')->get()
            ->filter(fn (MoneyTransaction $t) => $tx->kind === 'expense' ? $bdt($t) : $usd($t))
            // A believable taka-per-dollar rate.
            ->first(function (MoneyTransaction $t) use ($tx) {
                [$dollars, $taka] = $tx->kind === 'expense' ? [$tx->amount, $t->amount] : [$t->amount, $tx->amount];
                $rate = $dollars > 0 ? $taka / $dollars : 0;

                return $rate >= 90 && $rate <= 160;
            });
        if (! $other) {
            return null;
        }

        [$out, $in] = $tx->kind === 'expense' ? [$tx, $other] : [$other, $tx];
        $transfer = DB::transaction(function () use ($out, $in) {
            $transfer = Ledger::save([
                'kind' => 'transfer', 'account_id' => $out->account_id, 'to_account_id' => $in->account_id,
                'amount' => $out->amount, 'to_amount' => $in->amount, 'fee' => $out->fee,
                'description' => 'Auto · USDT sold', 'occurred_on' => $in->occurred_on->toDateString(),
            ]);
            InboxMessage::whereIn('money_transaction_id', [$out->id, $in->id])
                ->update(['money_transaction_id' => $transfer->id, 'note' => "USDT sold: {$out->account->name} → {$in->account->name}"]);
            Ledger::delete($out);
            Ledger::delete($in);

            return $transfer;
        });

        $rate = number_format($in->amount / max(1, $out->amount), 2);
        $this->notify->send('info', '💱 '.Notify::money($out->amount, 'USD').' USDT → '.Notify::money($in->amount, 'BDT'),
            "{$out->account->name} → {$in->account->name} · rate ৳{$rate} per USDT · a conversion, not spending or income.", route('personal.activity'), 'klok-money');

        return $transfer;
    }

    /** "Int: BDT 125.50", "Interest credited", Islamic banks' "profit": the bank's interest. */
    public static function isInterest(string $body): bool
    {
        return (bool) preg_match('/\bint\s*:|\binterest\b|\bprofit (?:credited|paid)\b/i', $body);
    }

    /** Keep what the bank says the balance is, to compare with ours. */
    private function rememberBankBalance(Account $account, array $p): void
    {
        // Only a newer alert says what the bank has now (an older one read again must not undo it).
        $at = $p['at'] ?? now();
        if (($p['balance'] ?? null) !== null && (! $account->bank_balance_at || $account->bank_balance_at->lte($at))) {
            $account->update(['bank_balance' => $p['balance'], 'bank_balance_at' => $at]);
        }
    }

    /** "💰 UCB ••4567 +৳20,000 · From X · Balance ৳45,500" */
    private function tellEntry(Account $account, array $p, ?string $extra = null, ?int $txId = null): void
    {
        $in = $p['direction'] === 'in';
        // My own money out: tapping it asks what it was for and for whom.
        $ask = ! $in && $account->scope === 'personal' && ! $extra ? 'Tap: what for, for whom?' : null;
        $name = ($account->scope === 'business' ? '💼 ' : '').$account->name.($account->last4 && ! str_contains($account->name, $account->last4) ? " ••{$account->last4}" : '');
        $parts = array_filter([
            $p['counterparty'] ? ($in ? 'From ' : 'To ').$p['counterparty'] : null,
            $extra,
            'Balance '.Notify::money(Ledger::balance($account->fresh()), $account->currency),
            $ask,
        ]);
        $this->notify->send($in ? 'credit' : 'debit', ($in ? '💰 ' : '💸 ').$name.' '.($in ? '+' : '−').Notify::money($p['amount'], $account->currency),
            implode(' · ', $parts), route('personal.activity', array_filter(['entry' => $txId])), 'klok-money');
    }

    /**
     * Already in the books? Either an entry carries this TrxID, or I typed the same
     * amount by hand within a day (auto entries never count here, so two genuine
     * payments of the same amount both get in).
     */
    private function alreadyRecorded(Account $account, array $p, string $date, string $source = 'sms'): bool
    {
        if ($p['reference'] && MoneyTransaction::where('account_id', $account->id)->where('description', 'like', "%{$p['reference']}%")->exists()) {
            return true;
        }

        $window = [CarbonImmutable::parse($date)->subDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString().' 23:59:59'];
        $same = MoneyTransaction::where('account_id', $account->id)->where('amount', $p['amount'])
            ->where('kind', $p['direction'] === 'in' ? 'income' : 'expense')->whereBetween('occurred_on', $window);

        // Typed by hand already.
        if ((clone $same)->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', 'Auto ·%'))->exists()) {
            return true;
        }

        // The same transaction already came in through another channel (SMS vs email vs app).
        $autoIds = (clone $same)->where('description', 'like', 'Auto ·%')->pluck('id');

        return $autoIds->isNotEmpty() && InboxMessage::whereIn('money_transaction_id', $autoIds)->where('source', '!=', $source)->exists();
    }

    /** An open invoice this payment is for: by TrxID, by what the client reported, by their phone, or the only one with that amount. */
    private function invoiceFor(array $p, string $body, Account $account): ?PersonalInvoice
    {
        if (strtoupper($account->currency) !== 'BDT' && strtoupper($p['currency']) !== 'BDT') {
            return null;
        }

        $open = PersonalInvoice::with('client')->whereIn('status', ['unpaid', 'partial'])->where('currency', 'BDT')->get();
        $digits = preg_replace('/\D/', '', $body);

        return $open->first(fn ($i) => $p['reference'] && strcasecmp((string) ($i->reported['reference'] ?? ''), $p['reference']) === 0)
            ?? $open->first(fn ($i) => $i->reported && (int) $i->reported['amount'] === $p['amount'] && ($i->reported['method'] ?? null) === $p['provider'])
            ?? $open->first(fn ($i) => $i->due() === $p['amount'] && $i->client->phone && str_contains($digits, substr(preg_replace('/\D/', '', $i->client->phone), -8)))
            ?? (($same = $open->filter(fn ($i) => $i->due() === $p['amount'] && $i->issued_on->gte(now()->subDays(60))))->count() === 1 ? $same->first() : null);
    }

    private function guess(string $text): string
    {
        $t = mb_strtolower($text);
        foreach (self::GUESS as $category => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}
