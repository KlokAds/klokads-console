<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP (e.g. Hostinger billing@klokads.com) configured from Settings, so every
 * email comes from Klok Ads — never from Stripe.
 */
class MailSettings
{
    public static function configured(): bool
    {
        return (bool) (Setting::get('smtp_host') && Setting::get('smtp_username') && Setting::get('smtp_password'));
    }

    /** @return array{host: ?string, port: int, username: ?string, from_address: ?string, from_name: string, has_password: bool, auto_receipts: bool, auto_invoices: bool} */
    public static function current(): array
    {
        return [
            'host' => Setting::get('smtp_host'),
            'port' => (int) Setting::get('smtp_port', 465),
            'username' => Setting::get('smtp_username'),
            'from_address' => Setting::get('mail_from_address') ?: Setting::get('smtp_username'),
            'from_name' => Setting::get('mail_from_name') ?: Setting::get('business_name', 'Klok Ads'),
            'has_password' => (bool) Setting::get('smtp_password'),
            'auto_receipts' => Setting::get('mail_auto_receipts', '1') === '1',
            'auto_invoices' => Setting::get('mail_auto_invoices', '1') === '1',
        ];
    }

    /** Point Laravel's mailer at the saved SMTP account. */
    public static function apply(): void
    {
        if (! self::configured()) {
            return;
        }

        $s = self::current();

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', array_merge(config('mail.mailers.smtp', []), [
            'transport' => 'smtp',
            // Port 465 = implicit TLS ("smtps"); 587 = STARTTLS ("smtp").
            'scheme' => $s['port'] === 465 ? 'smtps' : 'smtp',
            'host' => $s['host'],
            'port' => $s['port'],
            'username' => $s['username'],
            'password' => Setting::get('smtp_password'),
            'timeout' => 20,
        ]));
        Config::set('mail.from', ['address' => $s['from_address'], 'name' => $s['from_name']]);

        Mail::purge('smtp');
    }
}
