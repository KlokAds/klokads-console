<?php

namespace App\Support;

use App\Models\MoneyTransaction;
use App\Models\Setting;

/**
 * Which category I use for whom: once "MD ANOWAR HOSSAIN" is set to "Salary I pay", his next
 * bKash/bank alert is booked there by itself. Kept apart for personal and business money.
 */
class PayeeMemory
{
    /** Categories the app sets itself (or keeps private): never learned. */
    private const NEVER = ['sadaqah', 'envelope', 'bank_fees', 'work'];

    public static function remember(MoneyTransaction $tx): void
    {
        $tx->loadMissing('account');
        $key = self::key($tx->account?->scope, $tx->counterparty);
        if (! $key || ! $tx->category || $tx->kind === 'transfer' || in_array($tx->category, self::NEVER, true)
            || array_key_exists($tx->category, Catalog::LOAN_CATEGORIES)) {
            return;
        }
        $map = self::all();
        $map[$key] = $tx->category;
        Setting::put('payee_categories', json_encode(array_slice($map, -500, null, true)));
    }

    public static function category(?string $scope, ?string $counterparty): ?string
    {
        $key = self::key($scope, $counterparty);

        return $key ? self::all()[$key] ?? null : null;
    }

    /** "personal|mdanowarhossain", "personal|1811000000" (numbers by their last 10 digits). */
    private static function key(?string $scope, ?string $counterparty): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $counterparty);
        $who = strlen($digits) >= 10 ? substr($digits, -10) : preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $counterparty));

        return $who === '' ? null : ($scope ?: 'personal').'|'.$who;
    }

    /** @return array<string, string> */
    private static function all(): array
    {
        return json_decode((string) Setting::get('payee_categories'), true) ?: [];
    }
}
