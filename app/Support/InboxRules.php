<?php

namespace App\Support;

use App\Models\Setting;

/**
 * What the phone forwards: the bank / wallet SMS senders and wallet apps I switched on,
 * and words that mark a message as never-to-send (codes, PINs). One list, edited from
 * the phone app or the admin panel; the phone keeps a copy so it filters offline too.
 */
class InboxRules
{
    /** Starting list: name as it appears as the SMS sender (or the app's name), and where it comes from. */
    public const DEFAULT_SENDERS = [
        ['name' => 'bKash', 'type' => 'sms'], ['name' => 'NAGAD', 'type' => 'sms'], ['name' => '16216', 'type' => 'sms'],
        ['name' => 'upay', 'type' => 'sms'], ['name' => 'UCB', 'type' => 'sms'], ['name' => 'DHAKA BANK', 'type' => 'sms'], ['name' => 'CITY BANK', 'type' => 'sms'],
        ['name' => 'BRAC BANK', 'type' => 'sms'], ['name' => 'DBBL', 'type' => 'sms'], ['name' => 'EBL', 'type' => 'sms'],
        ['name' => 'IBBL', 'type' => 'sms'], ['name' => 'MTB', 'type' => 'sms'], ['name' => 'PRIME BANK', 'type' => 'sms'],
        ['name' => 'AB BANK', 'type' => 'sms'], ['name' => 'BANK ASIA', 'type' => 'sms'], ['name' => 'StanChart', 'type' => 'sms'],
        ['name' => 'Cellfin', 'type' => 'sms'], ['name' => 'nSave', 'type' => 'app'], ['name' => 'Rizon', 'type' => 'app'],
        ['name' => 'Elevate Pay', 'type' => 'app'], ['name' => 'WorldFirst', 'type' => 'app'], ['name' => 'Airwallex', 'type' => 'app'],
        ['name' => 'Payoneer', 'type' => 'app'], ['name' => 'RedotPay', 'type' => 'app'], ['name' => 'Binance', 'type' => 'app'],
    ];

    public const DEFAULT_IGNORE = ['otp', 'one time password', 'verification code', 'verification number', 'security code', 'your code is',
        'login code', 'access code', 'auth code', 'confirmation code', 'use code', 'pin'];

    /** @return array{senders: array<int, array{name: string, type: string, on: bool}>, ignore: array<int, string>, whitelist: bool} */
    public static function all(): array
    {
        $senders = json_decode((string) Setting::get('inbox_senders'), true);
        $ignore = json_decode((string) Setting::get('inbox_ignore'), true);

        return [
            'senders' => is_array($senders) ? $senders : array_map(fn ($s) => $s + ['on' => true], self::DEFAULT_SENDERS),
            'ignore' => is_array($ignore) ? $ignore : self::DEFAULT_IGNORE,
            'whitelist' => Setting::get('inbox_whitelist', '1') === '1',
        ];
    }

    /** @param  array{senders?: array, ignore?: array, whitelist?: bool}  $data */
    public static function save(array $data): array
    {
        if (array_key_exists('senders', $data)) {
            $seen = [];
            $senders = [];
            foreach ($data['senders'] as $s) {
                $name = trim((string) ($s['name'] ?? ''));
                $key = self::normalise($name);
                if ($name === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $senders[] = ['name' => mb_substr($name, 0, 40), 'type' => ($s['type'] ?? 'sms') === 'app' ? 'app' : 'sms', 'on' => (bool) ($s['on'] ?? true)];
            }
            Setting::put('inbox_senders', json_encode($senders));
        }
        if (array_key_exists('ignore', $data)) {
            $words = array_values(array_unique(array_filter(array_map(fn ($w) => mb_strtolower(trim((string) $w)), $data['ignore']))));
            Setting::put('inbox_ignore', json_encode($words));
        }
        if (array_key_exists('whitelist', $data)) {
            Setting::put('inbox_whitelist', $data['whitelist'] ? '1' : '0');
        }

        return self::all();
    }

    public static function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }

    /** A message with an ignore word (as a whole word) is never booked — unless it carries a transaction ID. */
    public static function ignored(string $text): bool
    {
        $t = mb_strtolower($text);
        if (preg_match('/trx ?id|txn ?id|transaction id/', $t)) {
            return false;
        }
        foreach (self::all()['ignore'] as $word) {
            if ($word !== '' && preg_match('/\b'.preg_quote($word, '/').'\b/u', $t)) {
                return true;
            }
        }

        return false;
    }
}
