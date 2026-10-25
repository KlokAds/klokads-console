<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The agency's dollar rate (BDT per USD of the dollars now being spent), read from KlokOne so my
 * own clients' ads are billed at the same rate. The last good one is kept for when KlokOne can't be reached.
 */
class KlokOneRate
{
    /** @return array{fx: float, source: string, at: ?string} */
    public static function current(): array
    {
        return Cache::remember('klokone-rate', now()->addHour(), function () {
            $url = Setting::get('klokone_url');
            $key = Setting::get('klokone_key');
            if ($url && $key) {
                try {
                    $res = Http::timeout(15)->acceptJson()->withHeaders(['X-Console-Key' => $key])->get(rtrim($url, '/').'/hooks/fx-rate');
                    if ($res->successful() && (float) $res->json('fx_rate') > 0) {
                        Setting::put('klokone_fx_rate', (string) round((float) $res->json('fx_rate'), 4));
                        Setting::put('klokone_fx_at', now()->toIso8601String());

                        return ['fx' => round((float) $res->json('fx_rate'), 4), 'source' => 'klokone', 'at' => now()->toIso8601String()];
                    }
                } catch (Throwable) {
                    // KlokOne away: the last known rate below
                }
            }

            return ['fx' => (float) Setting::get('klokone_fx_rate', '0'), 'source' => 'saved', 'at' => Setting::get('klokone_fx_at')];
        });
    }

    public static function fx(): float
    {
        return self::current()['fx'];
    }
}
