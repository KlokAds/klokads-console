<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Today's market rate between two currencies (open.er-api.com, no key), for money whose cost in the
 * reporting currency is unknown — dollars that came in as dollars. The last good rate is kept for
 * when the service can't be reached.
 */
class MarketRate
{
    /** Units of $to per 1 $from (e.g. usd → sgd ≈ 1.29), or null when never known. */
    public static function get(string $from, string $to): ?float
    {
        $from = strtolower($from);
        $to = strtolower($to);
        if ($from === $to) {
            return 1.0;
        }
        $key = "market_rate_{$from}_{$to}";

        return Cache::remember($key, now()->addHours(12), function () use ($from, $to, $key) {
            try {
                $res = Http::timeout(10)->acceptJson()->get('https://open.er-api.com/v6/latest/'.strtoupper($from));
                $rate = (float) $res->json('rates.'.strtoupper($to));
                if ($res->successful() && $rate > 0) {
                    Setting::put($key, (string) round($rate, 6));

                    return round($rate, 6);
                }
            } catch (Throwable) {
                // offline: the last one saved
            }

            return ($saved = (float) Setting::get($key, '0')) > 0 ? $saved : null;
        });
    }
}
