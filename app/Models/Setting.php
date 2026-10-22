<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Keys whose values are stored encrypted. */
    public const SECRET = ['stripe_secret', 'stripe_webhook_secret', 'smtp_password', 'push_private_key', 'cron_token', 'meta_token'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings', fn () => static::pluck('value', 'key')->all());

        if (! isset($all[$key]) || $all[$key] === '') {
            return $default;
        }

        return in_array($key, self::SECRET, true) ? Crypt::decryptString($all[$key]) : $all[$key];
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value !== null && $value !== '' && in_array($key, self::SECRET, true)) {
            $value = Crypt::encryptString($value);
        }

        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings');
    }
}
