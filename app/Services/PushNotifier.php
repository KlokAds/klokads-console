<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/** Sends notifications to phones/browsers (Web Push). Keys are created on first use. */
class PushNotifier
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function keys(): array
    {
        if (! Setting::get('push_public_key') || ! Setting::get('push_private_key')) {
            $keys = self::createKeys();
            Setting::put('push_public_key', $keys['publicKey']);
            Setting::put('push_private_key', $keys['privateKey']);
        }

        return ['publicKey' => Setting::get('push_public_key'), 'privateKey' => Setting::get('push_private_key')];
    }

    /**
     * A P-256 key pair in the VAPID format (base64url public point and private scalar).
     * On Windows OpenSSL needs its config file passed explicitly to make EC keys.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    private static function createKeys(): array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        if (PHP_OS_FAMILY === 'Windows' && is_file($cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = $cnf;
        }

        $key = openssl_pkey_new($options);
        if (! $key) {
            return VAPID::createVapidKeys();
        }

        $ec = openssl_pkey_get_details($key)['ec'];
        $pad = fn (string $bytes) => str_pad($bytes, 32, chr(0), STR_PAD_LEFT);
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'publicKey' => $b64(chr(4).$pad($ec['x']).$pad($ec['y'])), // uncompressed point
            'privateKey' => $b64($pad($ec['d'])),
        ];
    }

    /** Secret for the cron-job.org ping URL. */
    public static function cronToken(): string
    {
        if (! Setting::get('cron_token')) {
            Setting::put('cron_token', Str::random(40));
        }

        return Setting::get('cron_token');
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $message
     * @return bool false when the device has gone (unsubscribed / uninstalled) — it is then removed
     */
    public function send(PushSubscription $sub, array $message): bool
    {
        $keys = self::keys();
        $push = new WebPush(['VAPID' => [
            'subject' => 'mailto:'.(Setting::get('business_email') ?: 'billing@klokads.com'),
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 6 * 3600, 'urgency' => 'normal']);

        $report = $push->sendOneNotification(
            Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding,
            ]),
            json_encode($message + ['url' => route('personal.dashboard'), 'tag' => 'klok'], JSON_UNESCAPED_UNICODE),
        );

        if ($report->isSubscriptionExpired()) {
            $sub->delete();

            return false;
        }

        return $report->isSuccess();
    }
}
