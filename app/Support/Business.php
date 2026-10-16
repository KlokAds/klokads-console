<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The issuer details printed on invoices and receipts, plus GST settings.
 */
class Business
{
    /**
     * @return array{name: string, legal_name: string, uen: ?string, address: ?string, email: ?string, phone: ?string, website: ?string}
     */
    public static function profile(): array
    {
        $name = Setting::get('business_name', 'Klok Ads');

        return [
            'name' => $name,
            'legal_name' => Setting::get('business_legal_name') ?: $name,
            'uen' => Setting::get('business_uen'),
            'address' => Setting::get('business_address'),
            'email' => Setting::get('business_email'),
            'phone' => Setting::get('business_phone'),
            'website' => Setting::get('business_website'),
        ];
    }

    public static function gstEnabled(): bool
    {
        return Setting::get('gst_enabled') === '1' && self::gstRate() > 0;
    }

    public static function gstRate(): float
    {
        return (float) Setting::get('gst_rate', 9);
    }

    /** Rate actually charged now: 0 while GST is switched off. */
    public static function activeGstRate(): float
    {
        return self::gstEnabled() ? self::gstRate() : 0.0;
    }

    /** @return array{enabled: bool, rate: float, reg_no: ?string, label: string} */
    public static function gst(): array
    {
        return [
            'enabled' => self::gstEnabled(),
            'rate' => self::gstRate(),
            'reg_no' => Setting::get('gst_reg_no'),
            'label' => Setting::get('gst_label', 'GST'),
        ];
    }

    public static function templateFor(?string $service): string
    {
        $key = $service && isset(Catalog::DEFAULT_TEMPLATE_FOR[$service]) ? $service : 'other';
        $template = Setting::get("template_for_{$key}") ?: Catalog::DEFAULT_TEMPLATE_FOR[$key];

        return isset(Catalog::TEMPLATES[$template]) ? $template : 'pro_google';
    }
}
