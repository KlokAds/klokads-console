<?php

namespace App\Support;

/**
 * Reads a bank / wallet SMS or email alert:
 * which provider, money in or out, amount + currency, who, reference, fee.
 * Returns null for anything that isn't a completed transaction (OTPs, offers, failures).
 */
class TransactionParser
{
    /** Sender / text clues → provider key (matches Catalog::PROVIDERS). */
    private const PROVIDERS = [
        'bkash' => ['bkash'],
        'nagad' => ['nagad'],
        'rocket' => ['rocket', '16216', 'dbbl mobile'],
        'upay' => ['upay'],
        'city' => ['citybank', 'city bank', 'cbl'],
        'brac' => ['brac', 'bracbank'],
        'dbbl' => ['dbbl', 'dutch-bangla', 'dutch bangla'],
        'ebl' => ['ebl', 'eastern bank'],
        'islami' => ['islami', 'ibbl'],
        'ucb' => ['ucb', 'united commercial'],
        'dhaka' => ['dhaka bank', 'dhakabank', 'dhbl'],
        'midland' => ['midland', 'mdb'],
        'mtb' => ['mtb', 'mutual trust'],
        'prime' => ['prime bank', 'primebank'],
        'scb' => ['standard chartered', 'stanchart', 'sc bank'],
        'worldfirst' => ['worldfirst', 'world first'],
        'elevatepay' => ['elevate pay', 'elevatepay', 'elevate'],
        'payoneer' => ['payoneer'],
        'dbs' => ['dbs', 'posb', 'paylah'],
        'ocbc' => ['ocbc'],
        'uob' => ['uob'],
        'airwallex' => ['airwallex'],
        'aspire' => ['aspire'],
        'wise' => ['wise', 'transferwise'],
        'nsave' => ['nsave'],
        'redotpay' => ['redotpay', 'redot pay'],
        'binance' => ['binance'],
        'rizon' => ['rizon'],
        'revolut' => ['revolut'],
        'youtrip' => ['youtrip'],
        'grabpay' => ['grabpay', 'grab'],
    ];

    private const IN = ['deposit successful', 'deposit confirmed', 'you bought', 'bought ', 'int:', 'interest credited', 'interest paid', 'profit credited', 'profit paid', 'you have received', 'received from', 'received tk', 'received bdt', 'received sgd', 'money received', 'cash in', 'has been credited', 'credited with', 'credited by', 'credited to',
        'deposit', 'deposited', 'incoming', 'you received', "you've received", 'payment received', 'refund', 'added to your', 'add money', 'remittance received', 'salary'];

    private const OUT = ['you sold', 'sold ', 'sell order', 'send money', 'money sent', 'sent $', 'sent usd', 'sent sgd', 'sent bdt', 'sent tk', 'has been debited', 'debited with', 'debited by', 'debited from', 'payment tk', 'payment of', 'paid tk', 'you paid', 'you have paid', 'cash out',
        'withdraw', 'purchase', 'card transaction', 'card payment approved', 'paid $', 'paid us$', 'paid usd', 'paid sgd', 'paid bdt', 'spent', 'transferred', 'transfer of', 'bill pay', 'recharge', 'merchant payment', 'you sent', "you've sent", 'debit'];

    /** Not a completed transaction: codes, promotions, failures (whole words only). */
    private const NOT_MONEY = '/\b(otp|one[- ]time password|verification code|do not share|pin|offer|cashback up to|win|\d+% off|discount|failed|unsuccessful|declined|reversed|insufficient)\b/u';

    /**
     * @return array{provider: ?string, direction: string, amount: int, currency: string, counterparty: ?string, reference: ?string, fee: int, last4: ?string, balance: ?int}|null
     */
    public static function parse(?string $sender, string $text): ?array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $text));
        $t = mb_strtolower($raw);
        $s = mb_strtolower((string) $sender);

        // A real transaction ID outweighs promo text that some wallets append.
        if (preg_match(self::NOT_MONEY, $t) && ! preg_match('/trx ?id|txn ?id|transaction id/', $t)) {
            return null;
        }

        $direction = self::direction($t);
        [$amount, $currency] = self::amount($raw);
        if (! $direction || ! $amount) {
            return null;
        }

        return [
            'provider' => self::provider($s, $t),
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $currency,
            'counterparty' => self::counterparty($raw, $direction),
            'reference' => self::match('/\b(?:trx ?id|txn ?id|transaction id|ref(?:erence)?(?: no\.?)?)[\s:#.-]*([A-Z0-9]{6,})/i', $raw),
            'fee' => self::fee($raw),
            'last4' => self::last4($raw),
            'balance' => self::balance($raw),
        ];
    }

    private static function direction(string $t): ?string
    {
        $in = self::firstPos($t, self::IN);
        $out = self::firstPos($t, self::OUT);

        return match (true) {
            $in === null && $out === null => null,
            $out === null => 'in',
            $in === null => 'out',
            default => $in <= $out ? 'in' : 'out', // the earlier phrase says what happened
        };
    }

    private static function firstPos(string $t, array $words): ?int
    {
        $best = null;
        foreach ($words as $w) {
            $p = mb_strpos($t, $w);
            if ($p !== false && ($best === null || $p < $best)) {
                $best = $p;
            }
        }

        return $best;
    }

    /** First money amount with a currency marker: "Tk 1,000.00", "BDT5000", "৳500", "SGD 12.30", "S$50". */
    private static function amount(string $raw): array
    {
        // USDT / USDC (Binance, RedotPay) count as US dollars; crypto amounts can have more decimals.
        $pattern = '/(?:(?<cur>tk\.?|bdt|৳|sgd|s\$|usdt|usdc|usd|us\$|\$)\s*(?<a>\d[\d,]*(?:\.\d{1,8})?))|(?:(?<b>\d[\d,]*(?:\.\d{1,8})?)\s*(?<cur2>tk|bdt|taka|sgd|usdt|usdc|usd))/iu';
        if (! preg_match($pattern, $raw, $m)) {
            return [0, ''];
        }

        $cur = mb_strtolower($m['cur'] ?: ($m['cur2'] ?? ''));
        $num = $m['a'] !== '' ? $m['a'] : ($m['b'] ?? '');
        $currency = match (true) {
            in_array($cur, ['tk', 'tk.', 'bdt', '৳', 'taka'], true) => 'BDT',
            in_array($cur, ['sgd', 's$'], true) => 'SGD',
            default => 'USD',
        };

        return [(int) round(((float) str_replace(',', '', $num)) * 100), $currency];
    }

    private static function provider(string $sender, string $t): ?string
    {
        foreach ([$sender, $t] as $haystack) {
            foreach (self::PROVIDERS as $key => $clues) {
                foreach ($clues as $clue) {
                    if ($haystack !== '' && str_contains($haystack, $clue)) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    private static function counterparty(string $raw, string $direction): ?string
    {
        $patterns = $direction === 'in'
            ? ['/\bfrom\s+(?:a\/c[:\s]*)?([A-Za-z0-9 .&\'-]{3,40}?)(?=[.,]|\s+(?:to|on|at|via|fee|ref|trx|txn|balance)\b|$)/i', '/sender[:\s]+([0-9+ ]{6,}|[A-Za-z .]{3,40})/i']
            : ['/\bto\s+([A-Za-z0-9 .&\'-]{3,40}?)(?=[.,]|\s+(?:on|at|via|fee|ref|trx|txn|balance|successful)\b|$)/i', '/\bat\s+([A-Za-z0-9 .&\'-]{3,40}?)(?=[.,]|\s+(?:on|via|ref|trx|txn|balance)\b|$)/i', '/receiver[:\s]+([0-9+ ]{6,}|[A-Za-z .]{3,40})/i'];

        foreach ($patterns as $p) {
            if ($v = self::match($p, $raw)) {
                $v = trim($v);
                if (! preg_match('/^(your|you|the|a\/c|account)\b/i', $v)) {
                    return mb_substr($v, 0, 60);
                }
            }
        }

        return null;
    }

    /** My account's last digits: "A/C ***1234", "Acct no. 123XXXX4567", "card ending 1234". */
    private static function last4(string $raw): ?string
    {
        if (! preg_match('/(?:a\/c|acct?|account|card)(?: no\.?| number)?(?: ending(?: in)?)?[\s:#.(-]*([0-9xX*•.]*[xX*•][0-9xX*•.]*?\d{3,4}|\d{4,})\b/iu', $raw, $m)) {
            return null;
        }

        return preg_match('/(\d{3,4})$/', $m[1], $d) ? $d[1] : null;
    }

    /** The balance an alert reports after the transaction, in cents. */
    private static function balance(string $raw): ?int
    {
        $v = self::match('/\b(?:avl\.? bal(?:ance)?|available balance|current balance|new balance|balance|bal)[\s:.]*(?:is\s*)?(?:tk\.?|bdt|৳|sgd|s\$|usd|us\$|\$)?\s*(\d[\d,]*(?:\.\d{1,2})?)/iu', $raw);

        return $v !== null ? (int) round(((float) str_replace(',', '', $v)) * 100) : null;
    }

    private static function fee(string $raw): int
    {
        $fee = self::match('/fee[:\s]*(?:tk\.?|bdt|৳|sgd)?\s*(\d[\d,]*(?:\.\d{1,2})?)/iu', $raw);

        return $fee ? (int) round(((float) str_replace(',', '', $fee)) * 100) : 0;
    }

    private static function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[1] : null;
    }
}
