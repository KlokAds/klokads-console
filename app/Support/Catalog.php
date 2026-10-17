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
        'bank_fees' => 'Bank & transfer fees',
        'other' => 'Other',
    ];

    /** Money in, by scope (Money page). */
    public const INCOME_CATEGORIES = [
        'business' => [
            'stripe_payout' => 'Stripe payout',
            'client_payment' => 'Client paid directly',
            'owner_capital' => 'Owner put money in',
            'other_income' => 'Other income',
        ],
        'personal' => [
            'salary' => 'Salary / owner draw',
            'family' => 'From family',
            'gift' => 'Gift',
            'investment' => 'Investment return',
            'other_income' => 'Other income',
        ],
    ];

    /** Personal spending categories (business spending uses EXPENSE_CATEGORIES). */
    public const PERSONAL_CATEGORIES = [
        'food' => 'Food & groceries',
        'home' => 'Rent & home',
        'bills' => 'Bills & utilities',
        'transport' => 'Transport',
        'family' => 'Family support',
        'shopping' => 'Shopping',
        'health' => 'Health',
        'education' => 'Education',
        'entertainment' => 'Fun & eating out',
        'travel' => 'Travel',
        'savings' => 'Savings & investment',
        'loan' => 'Loan / EMI',
        'charity' => 'Charity / zakat',
        'bank_fees' => 'Bank & transfer fees',
        'other' => 'Other',
    ];

    public const ACCOUNT_KINDS = ['bank' => 'Bank', 'wallet' => 'Wallet (Wise, bKash…)', 'card' => 'Card', 'cash' => 'Cash'];

    /** Invoice / receipt PDF designs. "Classic" layouts follow the familiar ad-platform format, with Klok Ads as issuer. */
    public const TEMPLATES = [
        'classic_meta' => 'Classic — Meta ads layout',
        'pro_meta' => 'Klok Professional — Meta',
        'classic_google' => 'Classic — Google ads layout',
        'pro_google' => 'Klok Professional — Google',
    ];

    /** Default template per service. */
    public const DEFAULT_TEMPLATE_FOR = [
        'meta_ads' => 'classic_meta',
        'tiktok_ads' => 'classic_meta',
        'google_ads' => 'classic_google',
        'seo' => 'pro_google',
        'web' => 'pro_google',
        'retainer' => 'pro_google',
        'other' => 'pro_google',
    ];

    /** Units for campaign lines (Google-style "Quantity / Units"). */
    public const UNITS = ['Clicks', 'Impressions', 'Reach', 'Leads', 'Conversions', 'Days', 'Hours', 'Months'];

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
