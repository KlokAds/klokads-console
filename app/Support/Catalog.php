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
        'agro' => 'Agro business (Matir Khamar)',
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
            'interest' => 'Bank interest / profit',
            'other_income' => 'Other income',
        ],
        'personal' => [
            'salary' => 'Salary / owner draw',
            'work' => 'Work / invoices',
            'family' => 'From family',
            'gift' => 'Gift',
            'investment' => 'Investment return',
            'interest' => 'Bank interest / profit',
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
        'staff_salary' => 'Salary I pay (staff)',
        'shopping' => 'Shopping',
        'health' => 'Health',
        'education' => 'Education',
        'entertainment' => 'Fun & eating out',
        'travel' => 'Travel',
        'savings' => 'Savings & investment',
        'loan' => 'Loan / EMI',
        'charity' => 'Charity / zakat',
        'sadaqah' => 'Sadaqah (not recorded)',
        'envelope' => 'Monthly envelope',
        'bank_fees' => 'Bank & transfer fees',
        'other' => 'Other',
    ];

    /** Banks and wallets to pick from when adding an account: key => [label, kind]. */
    public const PROVIDERS = [
        'BDT' => [
            'bkash' => ['bKash', 'wallet'],
            'nagad' => ['Nagad', 'wallet'],
            'rocket' => ['Rocket', 'wallet'],
            'upay' => ['Upay', 'wallet'],
            'ucb' => ['UCB', 'bank'],
            'dhaka' => ['Dhaka Bank', 'bank'],
            'midland' => ['Midland Bank', 'bank'],
            'city' => ['City Bank', 'bank'],
            'brac' => ['BRAC Bank', 'bank'],
            'dbbl' => ['Dutch-Bangla Bank', 'bank'],
            'ebl' => ['Eastern Bank', 'bank'],
            'islami' => ['Islami Bank', 'bank'],
            'mtb' => ['Mutual Trust Bank', 'bank'],
            'prime' => ['Prime Bank', 'bank'],
            'scb' => ['Standard Chartered', 'bank'],
            'cash' => ['Cash', 'cash'],
            'other' => ['Other', 'bank'],
        ],
        'SGD' => [
            'airwallex' => ['Airwallex', 'wallet'],
            'worldfirst' => ['WorldFirst', 'wallet'],
            'dbs' => ['DBS / POSB', 'bank'],
            'ocbc' => ['OCBC', 'bank'],
            'uob' => ['UOB', 'bank'],
            'aspire' => ['Aspire', 'bank'],
            'wise' => ['Wise', 'wallet'],
            'youtrip' => ['YouTrip', 'wallet'],
            'revolut' => ['Revolut', 'wallet'],
            'grabpay' => ['GrabPay', 'wallet'],
            'cash' => ['Cash', 'cash'],
            'other' => ['Other', 'bank'],
        ],
        'USD' => [
            'nsave' => ['nSave', 'wallet'],
            'redotpay' => ['RedotPay', 'wallet'],
            'binance' => ['Binance (USDT)', 'wallet'],
            'rizon' => ['Rizon', 'wallet'],
            'elevatepay' => ['Elevate Pay', 'wallet'],
            'worldfirst' => ['WorldFirst', 'wallet'],
            'airwallex' => ['Airwallex', 'wallet'],
            'payoneer' => ['Payoneer', 'wallet'],
            'wise' => ['Wise', 'wallet'],
            'other' => ['Other', 'bank'],
        ],
    ];

    /**
     * Money that moves because of dena-paona: it changes balances but is neither
     * spending nor income, so month totals leave it out.
     */
    public const LOAN_CATEGORIES = [
        'loan_given' => 'Loan given',
        'loan_taken' => 'Loan taken',
        'loan_repaid_to_me' => 'Loan repaid to me',
        'loan_repaid_by_me' => 'Loan repaid by me',
    ];

    /** How clients pay my personal invoices. */
    public const PAY_METHODS = [
        'bank' => 'Bank transfer',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'upay' => 'Upay',
        'qr' => 'Bangla QR',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    /** The personal side only keeps these currencies. */
    public const PERSONAL_CURRENCIES = ['BDT', 'SGD', 'USD'];

    /**
     * Starting text for the family guide: how to bring each foreign account's money home to BDT.
     * Each account's own text (edited by me) replaces this.
     */
    public const WITHDRAW_GUIDES = [
        'redotpay' => "1. Open the RedotPay app and log in.\n2. Send the USDT to Binance (or sell it in RedotPay if it offers taka).\n3. In Binance: P2P → Sell USDT → pick a buyer who pays to your bKash / bank.\n4. Release the USDT only after the taka has arrived.",
        'binance' => "1. Open the Binance app and log in.\n2. P2P → Sell → USDT → BDT.\n3. Pick a buyer who pays to your bKash / Nagad / bank account.\n4. When the taka has arrived in that account, tap Release. Never release before the money is in.",
        'airwallex' => "1. Open the Airwallex app (or airwallex.com) and log in.\n2. Go to Transfers → New transfer.\n3. Pick a saved Bangladesh bank account, or add one: bank name, account name, account number, branch routing number.\n4. Send the SGD — it arrives in taka, usually in 1–3 working days.",
        'worldfirst' => "1. Open the WorldFirst app (or worldfirst.com) and log in.\n2. Choose Withdraw / Transfer out.\n3. Pick a saved Bangladesh bank account, or add one with its routing number.\n4. Choose the amount — it is paid in taka in 1–2 working days.",
        'nsave' => "1. Open the nSave app and log in.\n2. Choose Send / Withdraw.\n3. Pick a Bangladesh bank account or wallet as the receiver.\n4. Confirm — check the rate and fee shown before sending.",
        'rizon' => "1. Open the Rizon app and log in.\n2. Choose Withdraw / Send.\n3. Pick a Bangladesh bank account or bKash as the receiver.\n4. Confirm the amount and fee.",
        'elevatepay' => "1. Open the Elevate Pay app and log in.\n2. Choose Withdraw.\n3. Pick a Bangladesh bank account as the receiver.\n4. Confirm — it arrives in taka.",
        'payoneer' => "1. Open Payoneer and log in.\n2. Choose Withdraw → To bank account.\n3. Pick a Bangladesh bank account.\n4. Confirm — it arrives in taka in 1–2 working days.",
        'wise' => "1. Open the Wise app and log in.\n2. Choose Send → Bangladesh (BDT).\n3. Pick a bank account or bKash receiver.\n4. Confirm the amount.",
    ];

    /**
     * Sadaqah is between me and Allah: only the balance goes down. Who, why and any note are
     * erased, and it shows in no list, total, search or report.
     */
    public const SADAQAH = 'sadaqah';

    /** Personal categories that are set by the app, never picked by hand. */
    public const AUTO_CATEGORIES = ['envelope', 'bank_fees'];

    /** Mobile wallets: their "last 4" is my mobile number, not an account number. */
    public const MFS = ['bkash', 'nagad', 'rocket', 'upay'];

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
