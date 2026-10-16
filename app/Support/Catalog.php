<?php

namespace App\Support;

class Catalog
{
    /** Services we bill clients for. */
    public const SERVICES = [
        'meta_ads' => 'Meta Ads',
        'google_ads' => 'Google Ads',
        'tiktok_ads' => 'TikTok Ads',
        'seo' => 'SEO',
        'web' => 'Website / Dev',
        'retainer' => 'Retainer / Management',
        'other' => 'Other',
    ];

    /** Where our money goes. */
    public const EXPENSE_CATEGORIES = [
        'meta_ads' => 'Meta Ads spend',
        'google_ads' => 'Google Ads spend',
        'tiktok_ads' => 'TikTok Ads spend',
        'seo' => 'SEO tools / backlinks',
        'software' => 'Software subscriptions',
        'salary' => 'Salary / freelancers',
        'office' => 'Office / admin',
        'tax' => 'Tax / GST',
        'other' => 'Other',
    ];

    public const PAID_VIA = [
        'card' => 'Card',
        'bank' => 'Bank transfer',
        'paynow' => 'PayNow',
        'stripe' => 'Stripe balance',
        'cash' => 'Cash',
    ];

    public static function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => compact('value', 'label'))->values()->all();
    }
}
