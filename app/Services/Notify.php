<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One place every notification goes through: it is kept in the feed the phone app
 * reads (and shows as a phone notification), and sent as web push to browsers.
 */
class Notify
{
    public function __construct(private PushNotifier $push) {}

    public function send(string $kind, string $title, ?string $body = null, ?string $url = null, ?string $tag = null): AppNotification
    {
        $note = AppNotification::create(compact('kind', 'title', 'body', 'url', 'tag'));

        foreach (PushSubscription::all() as $sub) {
            try {
                $this->push->send($sub, array_filter(['title' => $title, 'body' => (string) $body, 'url' => $url, 'tag' => $tag]));
            } catch (Throwable) {
                // a dead browser never blocks the rest
            }
        }

        return $note;
    }

    /** Send only if this key was never sent before (e.g. "loan:5:due-1"). */
    public function once(string $key, string $kind, string $title, ?string $body = null, ?string $url = null): bool
    {
        try {
            DB::table('reminder_sends')->insert(['key' => $key, 'created_at' => now()]);
        } catch (QueryException) {
            return false;
        }
        $this->send($kind, $title, $body, $url, 'klok-'.$kind);

        return true;
    }

    /** "৳20,000", "S$12.50", "$250.00" */
    public static function money(int $cents, string $currency): string
    {
        $code = strtoupper($currency);
        $symbol = ['SGD' => 'S$', 'BDT' => '৳', 'USD' => '$'][$code] ?? $code.' ';

        return ($cents < 0 ? '−' : '').$symbol.number_format(abs($cents) / 100, 2);
    }
}
