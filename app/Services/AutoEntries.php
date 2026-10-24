<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InboxMessage;
use App\Models\MoneyTransaction;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\TransactionParser;
use Carbon\CarbonImmutable;
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
        'bills' => ['singtel', 'starhub', 'm1 ', 'circles', 'sp group', 'desco', 'dpdc', 'titas', 'wasa', 'grameenphone', 'robi', 'banglalink', 'recharge', 'internet'],
        'shopping' => ['shopee', 'lazada', 'daraz', 'amazon', 'uniqlo', 'aarong'],
        'health' => ['pharmacy', 'guardian', 'watsons', 'clinic', 'hospital', 'lazz'],
        'entertainment' => ['netflix', 'spotify', 'youtube', 'cinema', 'gv ', 'shaw'],
        'family' => ['ammu', 'abbu', 'family'],
    ];

    public function __construct(private PersonalInvoicing $invoicing) {}

    public static function token(): string
    {
        if (! Setting::get('inbox_token')) {
            Setting::put('inbox_token', Str::random(40));
        }

        return Setting::get('inbox_token');
    }

    /** Store an alert once and turn it into an entry if possible. */
    public function ingest(string $source, ?string $sender, string $body, ?CarbonImmutable $receivedAt = null): InboxMessage
    {
        $body = trim(mb_substr($body, 0, 5000));
        $parsed = TransactionParser::parse($sender, $body);
        // The wallet's own transaction ID identifies a payment even if the alert arrives twice (SMS + email).
        $hash = hash('sha256', $parsed['reference'] ?? null ? ($parsed['provider'] ?? '').'|'.$parsed['reference'] : $source.'|'.$sender.'|'.$body);

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

            return $message;
        }

        $date = ($message->received_at ?? now())->toDateString();
        if ($this->alreadyRecorded($account, $p, $date)) {
            $message->update(['status' => 'ignored', 'account_id' => $account->id, 'note' => 'Already recorded']);

            return $message;
        }

        try {
            // Money in that pays one of my invoices: record it against the invoice.
            if ($p['direction'] === 'in' && ! $category && ($invoice = $this->invoiceFor($p, $message->body, $account))) {
                $payment = $this->invoicing->recordPayment($invoice, min($p['amount'], $invoice->due()), $date, $p['provider'] ?? 'other', $p['reference'], $account->id, $message->source);
                $message->update(['status' => 'matched', 'account_id' => $account->id, 'personal_invoice_id' => $invoice->id, 'money_transaction_id' => $payment->money_transaction_id, 'note' => "Paid {$invoice->number}"]);

                return $message;
            }

            $tx = Ledger::save([
                'kind' => $p['direction'] === 'in' ? 'income' : 'expense',
                'account_id' => $account->id,
                'amount' => $p['amount'],
                'fee' => $p['fee'] ?? 0,
                'category' => $category ?? ($p['direction'] === 'in' ? 'other_income' : $this->guess($p['counterparty'].' '.$message->body)),
                'counterparty' => $p['counterparty'],
                'description' => 'Auto · '.ucfirst($p['provider'] ?? $message->source).' '.$message->source.($p['reference'] ? " · {$p['reference']}" : ''),
                'occurred_on' => $date,
            ]);
            $message->update(['status' => 'added', 'account_id' => $account->id, 'money_transaction_id' => $tx->id, 'note' => null]);
        } catch (Throwable $e) {
            $message->update(['status' => 'review', 'note' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $message;
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

    /** Provider + currency → account; the last 4 digits pick between several. */
    private function account(array $p): ?Account
    {
        if (! $p['provider']) {
            return null;
        }

        $candidates = Account::where('archived', false)->where('provider', $p['provider'])
            ->whereRaw('upper(currency) = ?', [strtoupper($p['currency'])])->orderByRaw("scope = 'personal' desc")->get();

        if ($candidates->count() > 1 && $p['last4']) {
            $byDigits = $candidates->filter(fn ($a) => str_contains($a->name.' '.$a->notes, $p['last4']));
            if ($byDigits->count() === 1) {
                return $byDigits->first();
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Already in the books? Either an entry carries this TrxID, or I typed the same
     * amount by hand within a day (auto entries never count here, so two genuine
     * payments of the same amount both get in).
     */
    private function alreadyRecorded(Account $account, array $p, string $date): bool
    {
        if ($p['reference'] && MoneyTransaction::where('account_id', $account->id)->where('description', 'like', "%{$p['reference']}%")->exists()) {
            return true;
        }

        return MoneyTransaction::where('account_id', $account->id)->where('amount', $p['amount'])
            ->where('kind', $p['direction'] === 'in' ? 'income' : 'expense')
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', 'Auto ·%'))
            ->whereBetween('occurred_on', [CarbonImmutable::parse($date)->subDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString().' 23:59:59'])
            ->exists();
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
