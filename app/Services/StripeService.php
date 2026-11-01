<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Payout;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Business;
use App\Support\Ledger;
use App\Support\Pricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Stripe\BalanceTransaction;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Subscription;
use Throwable;

class StripeService
{
    private ?StripeClient $client = null;

    public static function configured(): bool
    {
        return (bool) Setting::get('stripe_secret');
    }

    /**
     * Whether the dashboard works with live-mode data. Follows the connected key;
     * after a disconnect it keeps the last mode so history stays visible.
     */
    public static function isLive(): bool
    {
        if ($key = Setting::get('stripe_secret')) {
            return str_contains($key, '_live_');
        }

        return Setting::get('stripe_mode') === 'live';
    }

    private static function balanceCacheKey(): string
    {
        return 'stripe_balance_'.(self::isLive() ? 'live' : 'test');
    }

    public static function forgetBalance(): void
    {
        Cache::forget('stripe_balance_live');
        Cache::forget('stripe_balance_test');
    }

    public function stripe(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = Setting::get('stripe_secret');
        if (! $secret) {
            throw new RuntimeException('Stripe is not connected. Add your secret key in Settings.');
        }

        return $this->client = new StripeClient($secret);
    }

    public function currency(): string
    {
        return strtolower(Setting::get('currency', 'sgd'));
    }

    /** A client is charged in their own currency (their ad account's), else the account's default. */
    public function currencyFor(Client $client): string
    {
        return strtolower($client->currency ?: $this->currency());
    }

    // ---------------------------------------------------------------- customers

    public function ensureCustomer(Client $client): string
    {
        if ($existing = $client->stripeCustomerId()) {
            return $existing;
        }

        $customer = $this->stripe()->customers->create([
            'name' => $client->company ?: $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => ['country' => $client->country],
            'metadata' => ['klok_client_id' => $client->id, 'contact' => $client->name],
        ]);

        $client->update([Client::customerColumn() => $customer->id]);

        return $customer->id;
    }

    public function updateCustomer(Client $client): void
    {
        if (! $client->stripeCustomerId()) {
            return;
        }

        $this->stripe()->customers->update($client->stripeCustomerId(), [
            'name' => $client->company ?: $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
        ]);
    }

    // ---------------------------------------------------------------- saved cards

    /**
     * The client's saved cards, default first. A lone card with no default becomes the default.
     *
     * @return array<int, array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}>
     */
    public function paymentMethods(Client $client): array
    {
        $customerId = $client->stripeCustomerId();
        if (! $customerId) {
            return [];
        }

        $stripe = $this->stripe();
        $cards = $stripe->customers->allPaymentMethods($customerId, ['limit' => 20])->data;
        $default = $stripe->customers->retrieve($customerId)->invoice_settings?->default_payment_method;
        $default = is_object($default) ? $default->id : $default;

        if (! $default && count($cards) > 0) {
            $default = $cards[0]->id;
            $this->setDefaultPaymentMethod($client, $default);
        }

        return collect($cards)
            ->map(fn ($pm) => self::describePaymentMethod($pm) + ['is_default' => $pm->id === $default])
            ->sortByDesc('is_default')->values()->all();
    }

    /**
     * Card, Link, bank account, wallet… as one shape for the saved-methods list.
     *
     * @return array{id: string, type: string, brand: string, last4: ?string, exp_month: ?int, exp_year: ?int, detail: ?string}
     */
    public static function describePaymentMethod(object $pm): array
    {
        $type = $pm->type;
        $info = $pm->{$type} ?? null;

        return [
            'id' => $pm->id,
            'type' => $type,
            'brand' => $type === 'card' ? $info->brand : $type,
            'last4' => $info->last4 ?? null,
            'exp_month' => $type === 'card' ? $info->exp_month : null,
            'exp_year' => $type === 'card' ? $info->exp_year : null,
            'detail' => $info->bank_name ?? $info->email ?? $info->cashtag ?? null,
        ];
    }

    /** Make a card the default for invoices and for the client's active recurring plans. */
    public function setDefaultPaymentMethod(Client $client, string $paymentMethodId): void
    {
        $this->assertOwnsPaymentMethod($client, $paymentMethodId);
        $stripe = $this->stripe();

        $stripe->customers->update($client->stripeCustomerId(), [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $client->plans()->whereIn('status', ['active', 'trialing', 'past_due', 'incomplete'])->each(
            fn (RecurringPlan $plan) => $stripe->subscriptions->update($plan->stripe_subscription_id, ['default_payment_method' => $paymentMethodId])
        );
    }

    /** Recurring plans charge the default card, so it can't be removed while one is running. */
    public static function defaultCardLocked(Client $client): bool
    {
        return $client->plans()->whereIn('status', ['active', 'trialing', 'past_due', 'incomplete'])->exists();
    }

    public function removePaymentMethod(Client $client, string $paymentMethodId): void
    {
        $this->assertOwnsPaymentMethod($client, $paymentMethodId);
        $wasDefault = collect($this->paymentMethods($client))->firstWhere('id', $paymentMethodId)['is_default'] ?? false;

        if ($wasDefault && self::defaultCardLocked($client)) {
            throw new RuntimeException('This is the default card and it pays an active recurring plan. Make another card the default first, then remove this one.');
        }

        $this->stripe()->paymentMethods->detach($paymentMethodId);

        if ($wasDefault && ($next = $this->paymentMethods($client)[0]['id'] ?? null)) {
            $this->setDefaultPaymentMethod($client, $next);
        }
    }

    /**
     * Secret for saving a new card on OUR pages (admin popup or the client's add-card link).
     * The card box there is Stripe's secure field, so card numbers never reach our server.
     */
    public function cardSetupSecret(Client $client): string
    {
        $intent = $this->stripe()->setupIntents->create([
            'customer' => $this->ensureCustomer($client),
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['klok_client_id' => $client->id],
        ]);

        return $intent->client_secret;
    }

    /** Our own, branded add-card link for the client (valid 7 days). */
    public static function cardLink(Client $client): string
    {
        return URL::temporarySignedRoute('public.card', now()->addDays(7), ['client' => $client->id]);
    }

    /**
     * Charge a saved card right away for any amount. The invoice is our own (its number, receipt
     * and totals); Stripe only takes the card payment, so there is no Stripe Invoicing fee.
     * It is created once the card went through: a declined card leaves no invoice behind.
     */
    public function chargeNow(Client $client, int $amount, string $description, ?string $service = null, ?string $paymentMethodId = null, ?array $budget = null, ?string $statementText = null): Invoice
    {
        $items = $budget
            ? self::budgetItems($budget, $description)
            : [['description' => $description, 'amount' => $amount, 'quantity' => 1]];
        $paymentMethodId ??= $this->defaultCard($client);
        $this->assertOwnsPaymentMethod($client, $paymentMethodId);

        $lines = collect($items)->map(fn ($i) => ['description' => $i['description'], 'amount' => $i['amount'], 'quantity' => 1])->all();
        [$subtotal, $tax] = $this->totals($lines);
        $descriptor = self::cleanDescriptor($statementText) ?? self::descriptorFor($service, now());

        $pi = $this->stripe()->paymentIntents->create([
            'amount' => $subtotal + $tax,
            'currency' => $this->currencyFor($client),
            'customer' => $this->ensureCustomer($client),
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'description' => $description,
            'metadata' => ['klok_client_id' => $client->id, 'service' => $service ?? '', 'charged_from' => 'klokads-console'] + self::budgetMetadata($budget),
        ] + ($descriptor ? ['statement_descriptor_suffix' => $descriptor] : []), ['expand' => ['latest_charge']]);

        if ($pi->status !== 'succeeded') {
            throw new RuntimeException('The card payment did not go through ('.str_replace('_', ' ', $pi->status).').');
        }

        $invoice = $this->newInvoice($client, $lines, self::budgetColumns($budget) + [
            'description' => mb_substr($description, 0, 250),
            'service' => $service,
            'collection_method' => 'charge_automatically',
            'statement_descriptor' => $descriptor,
        ]);
        $this->stripe()->paymentIntents->update($pi->id, ['metadata' => ['klok_invoice_id' => $invoice->id]]);

        return $this->settle($invoice, $pi);
    }

    /** Charge an open invoice to a saved card (the default when none is given). */
    public function chargeInvoice(Invoice $invoice, ?string $paymentMethodId = null): Invoice
    {
        if ($invoice->stripe_invoice_id) {
            if ($paymentMethodId && $invoice->client) {
                $this->assertOwnsPaymentMethod($invoice->client, $paymentMethodId);
            }

            return $this->upsertInvoice($this->stripe()->invoices->pay($invoice->stripe_invoice_id, $paymentMethodId ? ['payment_method' => $paymentMethodId] : []));
        }

        $pi = $this->payWithCard($invoice, $paymentMethodId ?? $this->defaultCard($invoice->client), offSession: true);
        if ($pi->status !== 'succeeded') {
            throw new RuntimeException('The card payment did not go through ('.str_replace('_', ' ', $pi->status).').');
        }

        return $this->settle($invoice, $pi);
    }

    /**
     * The client pays their own invoice with a card they saved (they press Pay on the invoice page).
     *
     * @return array{paid: bool, client_secret: ?string} client_secret when the bank wants a 3-D Secure check first
     */
    public function payWithSavedCard(Invoice $invoice, string $paymentMethodId): array
    {
        $pi = $this->payWithCard($invoice, $paymentMethodId, offSession: false);
        if ($pi->status === 'succeeded') {
            $this->settle($invoice, $pi);

            return ['paid' => true, 'client_secret' => null];
        }
        if ($pi->status === 'requires_action') {
            return ['paid' => false, 'client_secret' => $pi->client_secret];
        }

        throw new RuntimeException('The card payment did not go through. Try another card.');
    }

    private function payWithCard(Invoice $invoice, ?string $paymentMethodId, bool $offSession): PaymentIntent
    {
        abort_unless($invoice->status === 'open' && $invoice->client && ! $invoice->stripe_invoice_id, 422);
        if (! $paymentMethodId) {
            throw new RuntimeException('This client has no saved card yet.');
        }
        $this->assertOwnsPaymentMethod($invoice->client, $paymentMethodId);

        $pi = $this->stripe()->paymentIntents->create([
            'amount' => $invoice->amount_remaining,
            'currency' => $invoice->currency,
            'customer' => $this->ensureCustomer($invoice->client),
            'payment_method' => $paymentMethodId,
            'confirm' => true,
            'description' => 'Invoice '.$invoice->number,
            'metadata' => ['klok_invoice_id' => $invoice->id, 'klok_client_id' => $invoice->client_id],
        ] + ($offSession ? ['off_session' => true] : ['return_url' => $invoice->publicUrl().'?paid=1'])
          + ($invoice->statement_descriptor ? ['statement_descriptor_suffix' => $invoice->statement_descriptor] : []), ['expand' => ['latest_charge']]);
        $invoice->update(['stripe_payment_intent_id' => $pi->id]);

        return $pi;
    }

    private function defaultCard(?Client $client): string
    {
        $id = $client ? (collect($this->paymentMethods($client))->firstWhere('is_default', true)['id'] ?? null) : null;
        if (! $id) {
            throw new RuntimeException('This client has no saved card yet.');
        }

        return $id;
    }

    // ---------------------------------------------------------------- our own invoices

    /** Next number of our own invoices: KA-0001, KA-0002… (per live / test mode). */
    public static function nextNumber(): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) Setting::get('invoice_prefix', 'KA'))) ?: 'KA';
        $last = Invoice::whereNull('stripe_invoice_id')->where('number', 'like', $prefix.'-%')->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))->max() ?? 0;

        return $prefix.'-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array{amount: int}>  $lines  line totals in cents
     * @return array{0: int, 1: int} subtotal and GST (when it is switched on)
     */
    private function totals(array $lines): array
    {
        $subtotal = (int) array_sum(array_column($lines, 'amount'));

        return [$subtotal, Business::gstEnabled() ? (int) round($subtotal * Business::gstRate() / 100) : 0];
    }

    /** @param  array<int, array{description: string, amount: int, quantity: int}>  $lines */
    private function newInvoice(Client $client, array $lines, array $fields): Invoice
    {
        [$subtotal, $tax] = $this->totals($lines);

        return DB::transaction(fn () => Invoice::create($fields + [
            'client_id' => $client->id,
            'number' => self::nextNumber(),
            'currency' => $fields['currency'] ?? $this->currencyFor($client),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $subtotal + $tax,
            'amount_paid' => 0,
            'amount_remaining' => $subtotal + $tax,
            'status' => 'open',
            'lines' => $lines,
            'issued_at' => now(),
        ]));
    }

    /** A card payment went through: the invoice is paid (and the receipt goes out). */
    public function settle(Invoice $invoice, PaymentIntent $pi): Invoice
    {
        if ($pi->status !== 'succeeded' || $invoice->status === 'paid') {
            return $invoice;
        }
        $before = clone $invoice;
        $charge = $pi->latest_charge;
        if ($charge && is_string($charge)) {
            $charge = $this->stripe()->charges->retrieve($charge);
        }
        $card = $charge?->payment_method_details?->card;

        $invoice->update([
            'status' => 'paid',
            'amount_paid' => $invoice->total,
            'amount_remaining' => 0,
            'paid_at' => now(),
            'stripe_payment_intent_id' => $pi->id,
            'paid_with' => $card ? ucfirst($card->brand).' ···· '.$card->last4 : ($charge?->payment_method_details?->type ?? null),
            'charge_id' => $charge?->id,
        ]);
        app(InvoiceMailer::class)->afterSync($before, $invoice);

        return $invoice;
    }

    private function assertOwnsPaymentMethod(Client $client, string $paymentMethodId): void
    {
        $pm = $this->stripe()->paymentMethods->retrieve($paymentMethodId);
        $owner = is_object($pm->customer) ? $pm->customer->id : $pm->customer;

        if (! $owner || $owner !== $client->stripeCustomerId()) {
            throw new RuntimeException('That card does not belong to this client.');
        }
    }

    // ---------------------------------------------------------------- GST

    /**
     * The Stripe tax rate for GST, created once per mode and rate. Null while GST is off.
     */
    public function gstTaxRateId(): ?string
    {
        if (! Business::gstEnabled()) {
            return null;
        }

        $gst = Business::gst();
        $key = 'stripe_tax_rate_'.(self::isLive() ? 'live' : 'test').'_'.str_replace('.', '_', (string) $gst['rate']);

        if ($id = Setting::get($key)) {
            return $id;
        }

        $rate = $this->stripe()->taxRates->create([
            'display_name' => $gst['label'],
            'percentage' => $gst['rate'],
            'inclusive' => false,
            'country' => 'SG',
            'jurisdiction' => 'SG',
            'tax_type' => 'gst',
            'description' => $gst['label'].' '.$gst['rate'].'%'.($gst['reg_no'] ? ' · Reg No '.$gst['reg_no'] : ''),
        ]);
        Setting::put($key, $rate->id);

        return $rate->id;
    }

    /** Invoice params that add GST when it is switched on. */
    private function taxParams(): array
    {
        return ($id = $this->gstTaxRateId()) ? ['default_tax_rates' => [$id]] : [];
    }

    // ---------------------------------------------------------------- bank statement text

    /** What the client's bank statement shows after the account prefix, per service. */
    public const DEFAULT_DESCRIPTORS = [
        'meta_ads' => 'META ADS',
        'google_ads' => 'GOOGLE ADS',
        'tiktok_ads' => 'TIKTOK ADS',
        'seo' => 'SEO',
        'web' => 'WEBSITE',
        'retainer' => 'MGMT FEE',
        'other' => 'SERVICES',
    ];

    /** Longest suffix that fits the 22-character card statement limit with the account prefix. */
    public static function descriptorMaxLength(): int
    {
        $prefix = (string) Setting::get('descriptor_prefix', 'KLOKADS');

        return max(5, 22 - strlen($prefix) - 2);
    }

    /**
     * Statement suffix for a service, e.g. "META ADS" → bank shows "KLOKADS* META ADS".
     * With a month (and the setting on) it becomes "KLOKADS* META ADS SEP"; the service
     * text is shortened if needed so the month always fits.
     */
    public static function descriptorFor(?string $service, ?\DateTimeInterface $month = null): ?string
    {
        $key = $service && array_key_exists($service, self::DEFAULT_DESCRIPTORS) ? $service : 'other';
        $text = self::cleanDescriptor(Setting::get("descriptor_{$key}") ?: self::DEFAULT_DESCRIPTORS[$key]);

        if (! $text || ! $month || Setting::get('descriptor_with_month', '1') !== '1') {
            return $text;
        }

        $suffix = ' '.strtoupper($month->format('M'));
        $room = self::descriptorMaxLength() - strlen($suffix);
        $words = explode(' ', $text);

        // Drop trailing words ("GOOGLE ADS" → "GOOGLE") rather than cutting a word in half.
        while (count($words) > 1 && strlen(implode(' ', $words)) > $room) {
            array_pop($words);
        }

        return self::cleanDescriptor(substr(implode(' ', $words), 0, $room).$suffix);
    }

    /** Uppercase, only characters card networks accept, within the length limit. */
    public static function cleanDescriptor(?string $text): ?string
    {
        $text = strtoupper(trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 .\-&]/', '', (string) $text))));
        $text = rtrim(substr($text, 0, self::descriptorMaxLength()));

        return preg_match('/[A-Z]/', $text) ? $text : null;
    }

    // ---------------------------------------------------------------- ad budget & commission

    /** Local columns that hold an ad-budget breakdown (see App\Support\Pricing). */
    public const BUDGET_FIELDS = ['net_amount', 'processing_fee', 'commission_rate', 'commission_amount', 'ad_budget', 'client_spend'];

    /**
     * Budget line plus the card processing fee line the client pays on top.
     *
     * @param  array{net: int, processing_fee: int}  $budget
     * @return array<int, array{description: string, amount: int, quantity: int}>
     */
    public static function budgetItems(array $budget, string $label): array
    {
        // Pay-as-you-spend: the spend we fronted on Meta, then our commission on it.
        if (($budget['mode'] ?? null) === 'spend') {
            $rate = rtrim(rtrim(number_format((float) $budget['commission_rate'], 2), '0'), '.');
            $items = [
                ['description' => $label, 'amount' => $budget['spend'], 'quantity' => 1],
                ['description' => "Management commission — {$rate}% of ad spend", 'amount' => $budget['commission'], 'quantity' => 1],
            ];
            if ($budget['processing_fee'] > 0) {
                $items[] = ['description' => 'Card processing fee', 'amount' => $budget['processing_fee'], 'quantity' => 1];
            }

            return $items;
        }

        $items = [['description' => isset($budget['client_spend']) ? self::commissionLine($budget, $label) : $label, 'amount' => $budget['net'], 'quantity' => 1]];

        if ($budget['processing_fee'] > 0) {
            $items[] = ['description' => 'Card processing fee', 'amount' => $budget['processing_fee'], 'quantity' => 1];
        }

        return $items;
    }

    /** "Management commission — 20% of 1,000.00 Meta Ads spend (paid directly by you)" */
    public static function commissionLine(array $budget, string $label): string
    {
        $what = trim(preg_replace('/\s*budget$/i', '', $label)) ?: 'ad';
        $rate = rtrim(rtrim(number_format((float) $budget['commission_rate'], 2), '0'), '.');

        return "Management commission — {$rate}% of ".number_format($budget['client_spend'] / 100, 2)." {$what} spend (paid directly by you)";
    }

    /** @return array<string, string> Stripe metadata for a Pricing::breakdown() */
    public static function budgetMetadata(?array $budget): array
    {
        if (! $budget) {
            return [];
        }

        return [
            'net_amount' => (string) $budget['net'],
            'processing_fee' => (string) $budget['processing_fee'],
            'commission_rate' => (string) $budget['commission_rate'],
            'commission_amount' => (string) $budget['commission'],
            'ad_budget' => (string) $budget['ad_budget'],
        ] + (isset($budget['client_spend']) ? ['client_spend' => (string) $budget['client_spend']] : []);
    }

    /** @return array<string, int|float> local columns from Stripe metadata, or [] when absent */
    private static function budgetFields(mixed $metadata): array
    {
        if (! $metadata || ! isset($metadata['net_amount'])) {
            return [];
        }

        return [
            'net_amount' => (int) $metadata['net_amount'],
            'processing_fee' => (int) ($metadata['processing_fee'] ?? 0),
            'commission_rate' => (float) ($metadata['commission_rate'] ?? 0),
            'commission_amount' => (int) ($metadata['commission_amount'] ?? 0),
            'ad_budget' => (int) ($metadata['ad_budget'] ?? 0),
            'client_spend' => isset($metadata['client_spend']) ? (int) $metadata['client_spend'] : null,
        ];
    }

    /** Local columns for a Pricing::breakdown() */
    public static function budgetColumns(?array $budget): array
    {
        return $budget ? self::budgetFields(self::budgetMetadata($budget)) : [];
    }

    // ---------------------------------------------------------------- invoices

    /**
     * @param  array<int, array{description: string, amount: int, quantity: int}>  $items  amounts in cents
     */
    /**
     * An invoice of our own: numbered and kept here, paid on our invoice page by card (Stripe
     * only takes the payment, so no Stripe Invoicing fee) or marked paid by bank transfer.
     */
    public function createInvoice(Client $client, array $items, array $opts): Invoice
    {
        if (! empty($opts['budget'])) {
            $items = self::budgetItems($opts['budget'], $opts['budget_label'] ?? 'Ads budget');
        }

        $lines = collect($items)->map(function ($item) {
            $qty = max(1, (int) $item['quantity']);
            $unit = $item['unit'] ?? null;

            // With a unit (Clicks, Impressions…) the quantity is usage and the amount is the line total.
            return [
                'description' => match (true) {
                    (bool) $unit => "{$item['description']} — ".number_format($qty)." {$unit}",
                    $qty > 1 => "{$item['description']} × {$qty}",
                    default => $item['description'],
                },
                'amount' => $unit ? $item['amount'] : $item['amount'] * $qty,
                'quantity' => 1,
            ];
        })->values()->all();

        return $this->newInvoice($client, $lines, self::budgetColumns($opts['budget'] ?? null) + [
            'currency' => strtolower($opts['currency'] ?? $this->currencyFor($client)),
            'description' => isset($opts['memo']) ? mb_substr((string) $opts['memo'], 0, 250) : null,
            'service' => $opts['service'] ?? null,
            'collection_method' => 'send_invoice',
            'due_at' => now()->addDays((int) ($opts['days_until_due'] ?? 7)),
            'statement_descriptor' => self::cleanDescriptor($opts['statement_descriptor'] ?? null) ?? self::descriptorFor(
                $opts['service'] ?? null,
                isset($opts['details']['period_start']) ? Carbon::parse($opts['details']['period_start']) : now(),
            ),
            'template' => $opts['template'] ?? Business::templateFor($opts['service'] ?? null),
            'details' => $opts['details'] ?? null,
        ]);
    }

    /**
     * Secret for the card box on our public invoice page (the invoice's PaymentIntent).
     * Null when the invoice can't be paid by card right now.
     */
    public function paymentSecret(Invoice $invoice): ?string
    {
        if ($invoice->status !== 'open') {
            return null;
        }
        if (! $invoice->stripe_invoice_id) {
            return $this->invoicePaymentIntent($invoice)?->client_secret;
        }

        $si = $this->stripe()->invoices->retrieve($invoice->stripe_invoice_id, ['expand' => ['confirmation_secret']]);

        return $si->status === 'open' ? ($si->confirmation_secret?->client_secret ?? null) : null;
    }

    /**
     * Card and charge behind a paid invoice, for receipts. Cached on the invoice.
     *
     * @return array{paid_with: ?string, charge_id: ?string}
     */
    public function paymentInfo(Invoice $invoice): array
    {
        if ($invoice->paid_with || $invoice->status !== 'paid' || ! self::configured() || ! $invoice->stripe_invoice_id) {
            return ['paid_with' => $invoice->paid_with, 'charge_id' => $invoice->charge_id];
        }

        $si = $this->stripe()->invoices->retrieve($invoice->stripe_invoice_id, ['expand' => ['payments.data.payment.payment_intent']]);
        $pi = $si->payments->data[0]->payment->payment_intent ?? null;

        if (! $pi || ! $pi->latest_charge) {
            return ['paid_with' => $invoice->amount_paid && ! $pi ? 'bank transfer' : null, 'charge_id' => null];
        }

        $charge = $this->stripe()->charges->retrieve(is_string($pi->latest_charge) ? $pi->latest_charge : $pi->latest_charge->id);
        $card = $charge->payment_method_details?->card;
        $info = [
            'paid_with' => $card ? ucfirst($card->brand).' ···· '.$card->last4 : ($charge->payment_method_details?->type ?? null),
            'charge_id' => $charge->id,
        ];
        $invoice->update($info);

        return $info;
    }

    /**
     * The card payment behind our own invoice's pay box: the open one is reused while its amount
     * still fits, a new one made otherwise. Null (and the invoice settled) when it was paid already.
     */
    private function invoicePaymentIntent(Invoice $invoice): ?PaymentIntent
    {
        if ($invoice->stripe_payment_intent_id) {
            $pi = $this->stripe()->paymentIntents->retrieve($invoice->stripe_payment_intent_id, ['expand' => ['latest_charge']]);
            if ($pi->status === 'succeeded') {
                $this->settle($invoice, $pi);

                return null;
            }
            if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true) && $pi->amount === $invoice->amount_remaining) {
                return $pi;
            }
        }

        $pi = $this->stripe()->paymentIntents->create([
            'amount' => $invoice->amount_remaining,
            'currency' => $invoice->currency,
            'automatic_payment_methods' => ['enabled' => true],
            'description' => 'Invoice '.$invoice->number,
            'metadata' => ['klok_invoice_id' => $invoice->id, 'klok_client_id' => $invoice->client_id],
        ] + ($invoice->client ? ['customer' => $this->ensureCustomer($invoice->client)] : [])
          + ($invoice->statement_descriptor ? ['statement_descriptor_suffix' => $invoice->statement_descriptor] : []));
        $invoice->update(['stripe_payment_intent_id' => $pi->id]);

        return $pi;
    }

    /** No card payment can finish any more (voided, or paid another way). */
    private function cancelPaymentIntent(Invoice $invoice): void
    {
        if (! $invoice->stripe_payment_intent_id) {
            return;
        }
        try {
            $this->stripe()->paymentIntents->cancel($invoice->stripe_payment_intent_id);
        } catch (Throwable) {
            // Already finished or cancelled.
        }
    }

    public function voidInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->stripe_invoice_id) {
            return $this->upsertInvoice($this->stripe()->invoices->voidInvoice($invoice->stripe_invoice_id));
        }
        $this->refreshInvoice($invoice);
        if ($invoice->status === 'paid') {
            throw new RuntimeException('The client has just paid this invoice — it can no longer be voided.');
        }
        $this->cancelPaymentIntent($invoice);
        $invoice->update(['status' => 'void']);

        return $invoice;
    }

    /** Client paid outside Stripe (bank transfer / PayNow). */
    public function markInvoicePaid(Invoice $invoice): Invoice
    {
        if ($invoice->stripe_invoice_id) {
            return $this->upsertInvoice(
                $this->stripe()->invoices->pay($invoice->stripe_invoice_id, ['paid_out_of_band' => true])
            );
        }
        $this->cancelPaymentIntent($invoice);
        $before = clone $invoice;
        $invoice->update(['status' => 'paid', 'amount_paid' => $invoice->total, 'amount_remaining' => 0, 'paid_at' => now(), 'paid_with' => 'bank transfer']);
        app(InvoiceMailer::class)->afterSync($before, $invoice);

        return $invoice;
    }

    public function refreshInvoice(Invoice $invoice): Invoice
    {
        if (! $invoice->stripe_invoice_id) {
            if ($invoice->status === 'open' && $invoice->stripe_payment_intent_id) {
                $this->settle($invoice, $this->stripe()->paymentIntents->retrieve($invoice->stripe_payment_intent_id, ['expand' => ['latest_charge']]));
            }

            return $invoice;
        }

        return $this->upsertInvoice($this->stripe()->invoices->retrieve($invoice->stripe_invoice_id));
    }

    /** Stripe says a card payment went through: the invoice it pays (ours) is paid. */
    public function paymentSucceeded(PaymentIntent $pi): void
    {
        $id = $pi->metadata['klok_invoice_id'] ?? null;
        $invoice = $id ? Invoice::withoutGlobalScope('stripe_mode')->whereNull('stripe_invoice_id')->find($id) : null;
        if ($invoice && $invoice->status === 'open') {
            $this->settle($invoice, $this->stripe()->paymentIntents->retrieve($pi->id, ['expand' => ['latest_charge']]));
        }
    }

    public function upsertInvoice(\Stripe\Invoice $si): Invoice
    {
        $subscriptionId = $si->parent?->subscription_details?->subscription ?? null;
        if (is_object($subscriptionId)) {
            $subscriptionId = $subscriptionId->id;
        }

        $customerId = is_string($si->customer) ? $si->customer : $si->customer?->id;
        $plan = $subscriptionId ? RecurringPlan::where('stripe_subscription_id', $subscriptionId)->first() : null;

        $clientId = $si->metadata['klok_client_id'] ?? null
            ?: $plan?->client_id
            ?: Client::findByStripeCustomer($customerId)?->id;

        $service = ($si->metadata['service'] ?? null) ?: $plan?->service;

        $description = $si->description
            ?: ($si->lines->data[0]->description ?? null)
            ?: $plan?->description;

        $existing = Invoice::where('stripe_invoice_id', $si->id)->first();

        // Commission details ride along in Stripe metadata; subscription invoices inherit the plan's.
        $budget = self::budgetFields($si->metadata)
            ?: self::budgetFields($si->parent?->subscription_details?->metadata ?? null)
            ?: ($plan && $plan->net_amount !== null ? $plan->only(self::BUDGET_FIELDS) : []);

        $saved = Invoice::updateOrCreate(['stripe_invoice_id' => $si->id], $budget + [
            'client_id' => $clientId ?: $existing?->client_id,
            'stripe_subscription_id' => $subscriptionId,
            'number' => $si->number,
            'description' => $description ? mb_substr($description, 0, 250) : null,
            'service' => $service ?: $existing?->service,
            'currency' => $si->currency,
            'total' => $si->total,
            'amount_paid' => $si->amount_paid,
            'amount_remaining' => $si->amount_remaining,
            'status' => $si->status,
            'collection_method' => $si->collection_method,
            'hosted_invoice_url' => $si->hosted_invoice_url,
            'invoice_pdf' => $si->invoice_pdf,
            'subtotal' => $si->subtotal,
            'tax_amount' => (int) collect($si->total_taxes ?? [])->sum('amount'),
            'lines' => collect($si->lines->data ?? [])->map(fn ($line) => [
                'description' => $line->description,
                'amount' => $line->amount,
                'quantity' => $line->quantity ?? 1,
                'period_start' => $line->period?->start,
                'period_end' => $line->period?->end,
            ])->values()->all(),
            'issued_at' => $this->ts($si->status_transitions->finalized_at ?? null) ?? $this->ts($si->created),
            'due_at' => $this->ts($si->due_date),
            'paid_at' => $this->ts($si->status_transitions->paid_at ?? null),
        ]);

        // Our own receipt / recurring-invoice emails (Stripe's are switched off).
        app(InvoiceMailer::class)->afterSync($existing, $saved);

        return $saved;
    }

    // ---------------------------------------------------------------- recurring

    /**
     * collection "auto": client pays the first invoice by card, the card is saved and
     * every next cycle is charged automatically. "invoice": Stripe emails an invoice every cycle.
     */
    public function createRecurring(Client $client, array $data): RecurringPlan
    {
        $stripe = $this->stripe();
        $currency = $data['currency'] ?? $this->currency();

        $budget = $data['budget'] ?? null;
        $recurring = ['interval' => $data['interval'], 'interval_count' => $data['interval_count']];
        $lines = $budget
            ? self::budgetItems($budget, $data['description'])
            : [['description' => $data['description'], 'amount' => $data['amount'], 'quantity' => 1]];

        $items = collect($lines)->map(function ($line) use ($stripe, $currency, $recurring, $data) {
            // Subscription invoices take the statement text from the first item's product.
            $product = $stripe->products->create(array_filter([
                'name' => $line['description'],
                'metadata' => ['service' => $data['service'] ?? ''],
                'statement_descriptor' => self::cleanDescriptor($data['statement_descriptor'] ?? null) ?? self::descriptorFor($data['service'] ?? null),
            ]));

            return ['price_data' => [
                'currency' => $currency,
                'product' => $product->id,
                'unit_amount' => $line['amount'],
                'recurring' => $recurring,
            ]];
        })->all();

        $params = [
            'customer' => $this->ensureCustomer($client),
            'items' => $items,
            'description' => $data['description'],
            'metadata' => ['klok_client_id' => $client->id, 'service' => $data['service'] ?? ''] + self::budgetMetadata($budget),
            'expand' => ['latest_invoice'],
        ] + $this->taxParams();

        if (! empty($data['start_date'])) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            if ($start->isFuture()) {
                $params['billing_cycle_anchor'] = $start->timestamp;
                $params['proration_behavior'] = 'none';
            }
        }

        if ($data['collection'] === 'auto') {
            $params['collection_method'] = 'charge_automatically';
            $params['payment_behavior'] = 'default_incomplete';
            $params['payment_settings'] = ['save_default_payment_method' => 'on_subscription'];
        } else {
            $params['collection_method'] = 'send_invoice';
            $params['days_until_due'] = $data['days_until_due'] ?? 7;
        }

        $sub = $stripe->subscriptions->create($params);

        $plan = RecurringPlan::create(self::budgetColumns($budget) + [
            'client_id' => $client->id,
            'stripe_subscription_id' => $sub->id,
            'description' => $data['description'],
            'service' => $data['service'] ?? null,
            'amount' => $budget ? $budget['charge'] : $data['amount'],
            'currency' => $currency,
            'interval' => $data['interval'],
            'interval_count' => $data['interval_count'],
            'collection' => $data['collection'],
            'status' => $sub->status,
            'current_period_end' => $this->ts($sub->items->data[0]->current_period_end ?? null),
            'first_invoice_url' => $sub->latest_invoice?->hosted_invoice_url ?? null,
        ]);

        if ($sub->latest_invoice instanceof \Stripe\Invoice) {
            $invoice = $sub->latest_invoice;
            if ($invoice->status === 'draft') {
                $invoice = $stripe->invoices->finalizeInvoice($invoice->id);
            }
            // A new open subscription invoice triggers our own invoice email (see InvoiceMailer::afterSync).
            $local = $this->upsertInvoice($invoice);
            $plan->update(['first_invoice_url' => $local->publicUrl()]);
        }

        return $plan;
    }

    public function cancelRecurring(RecurringPlan $plan): void
    {
        $sub = $this->stripe()->subscriptions->cancel($plan->stripe_subscription_id);
        $plan->update(['status' => $sub->status]);
    }

    public function upsertSubscription(Subscription $sub): void
    {
        RecurringPlan::where('stripe_subscription_id', $sub->id)->update([
            'status' => $sub->status,
            'current_period_end' => $this->ts($sub->items->data[0]->current_period_end ?? null),
        ]);
    }

    // ---------------------------------------------------------------- payment links

    public function createPaymentLink(array $data, ?Client $client): PaymentLink
    {
        $stripe = $this->stripe();
        $currency = $data['currency'] ?? $this->currency();
        $budget = $data['budget'] ?? null;
        $meta = ['klok_client_id' => $client?->id ?? '', 'service' => $data['service'] ?? ''] + self::budgetMetadata($budget);
        $lines = $budget
            ? self::budgetItems($budget, $data['description'])
            : [['description' => $data['description'], 'amount' => $data['amount'], 'quantity' => 1]];

        // Payment links can't carry a Stripe tax rate, so GST is its own line.
        if (Business::gstEnabled()) {
            $gst = Business::gst();
            $pricing = Pricing::forClient($client);
            $tax = $budget['tax'] ?? collect($lines)->sum(fn ($l) => $pricing->taxOn($l['amount']));
            $lines[] = ['description' => "{$gst['label']} {$gst['rate']}%", 'amount' => $tax, 'quantity' => 1];
        }

        $lineItems = collect($lines)->map(fn ($line) => [
            'price' => $stripe->prices->create([
                'currency' => $currency,
                'unit_amount' => $line['amount'],
                'product_data' => ['name' => $line['description']],
            ])->id,
            'quantity' => 1,
        ])->all();

        $link = $stripe->paymentLinks->create([
            'line_items' => $lineItems,
            'metadata' => $meta,
            // Every payment produces an invoice + receipt, so it lands in our invoice totals too.
            'invoice_creation' => ['enabled' => true, 'invoice_data' => ['metadata' => $meta, 'description' => $data['description']]],
            'payment_intent_data' => array_filter([
                'metadata' => $meta,
                'description' => $data['description'],
                'statement_descriptor_suffix' => self::cleanDescriptor($data['statement_descriptor'] ?? null) ?? self::descriptorFor($data['service'] ?? null),
            ]),
            'customer_creation' => 'always',
        ]);

        return PaymentLink::create(self::budgetColumns($budget) + [
            'client_id' => $client?->id,
            'stripe_payment_link_id' => $link->id,
            'url' => $link->url,
            'description' => $data['description'],
            'service' => $data['service'] ?? null,
            'amount' => $budget ? $budget['charge'] : $data['amount'],
            'currency' => $currency,
            'active' => true,
        ]);
    }

    public function setPaymentLinkActive(PaymentLink $link, bool $active): void
    {
        $this->stripe()->paymentLinks->update($link->stripe_payment_link_id, ['active' => $active]);
        $link->update(['active' => $active]);
    }

    // ---------------------------------------------------------------- payouts

    public const PAYOUT_INTERVALS = ['daily', 'weekly', 'monthly', 'manual'];

    public const PAYOUT_WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * The account's payout schedule and status, straight from Stripe.
     *
     * @return array{interval: ?string, weekly_days: array<int, string>, monthly_days: array<int, int>, status: ?string, delay_days: ?int}
     */
    public function payoutSettings(): array
    {
        $payments = $this->stripe()->balanceSettings->retrieve()->payments;
        $schedule = $payments->payouts?->schedule;

        return [
            'interval' => $schedule?->interval,
            'weekly_days' => array_values((array) ($schedule?->weekly_payout_days ?? [])),
            'monthly_days' => array_map('intval', array_values((array) ($schedule?->monthly_payout_days ?? []))),
            'status' => $payments->payouts?->status,
            'delay_days' => $payments->settlement_timing?->delay_days,
        ];
    }

    /** Change when Stripe pays out automatically, or switch to manual payouts. */
    public function updatePayoutSchedule(string $interval, ?string $weekday = null, ?int $monthDay = null): array
    {
        $schedule = ['interval' => $interval];

        if ($interval === 'weekly') {
            $schedule['weekly_payout_days'] = [$weekday ?? 'monday'];
        }

        if ($interval === 'monthly') {
            $schedule['monthly_payout_days'] = [$monthDay ?? 1];
        }

        $this->stripe()->balanceSettings->update(['payments' => ['payouts' => ['schedule' => $schedule]]]);

        return $this->payoutSettings();
    }

    /** Available balance in one currency, read fresh (never cached) before paying out. */
    public function availableBalance(string $currency): int
    {
        return (int) collect($this->stripe()->balance->retrieve()->available)
            ->where('currency', strtolower($currency))->sum('amount');
    }

    /** Send money from the Stripe balance to the bank now. Only allowed on a manual schedule. */
    public function createPayout(int $amount, string $currency, ?string $description = null): Payout
    {
        $payout = $this->stripe()->payouts->create(array_filter([
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => $description,
            'metadata' => ['created_from' => 'klokads-console'],
        ]));

        self::forgetBalance();

        return $this->upsertPayout($payout);
    }

    // ---------------------------------------------------------------- balance & sync

    /** Live Stripe balance, cached briefly. */
    public function balance(): ?array
    {
        if (! self::configured()) {
            return null;
        }

        return Cache::remember(self::balanceCacheKey(), 300, function () {
            $b = $this->stripe()->balance->retrieve();
            $sum = fn ($list) => collect($list)->groupBy('currency')
                ->map(fn ($rows) => $rows->sum('amount'))->all();

            return ['available' => $sum($b->available), 'pending' => $sum($b->pending)];
        });
    }

    /** Pull everything that changed since last sync (or the last $days on first run). */
    public function sync(int $days = 365): array
    {
        $since = Setting::get('last_sync_at');
        $from = $since ? Carbon::parse($since)->subDays(2) : now()->subDays($days);
        $startedAt = now();

        $this->syncAccount();

        $counts = [
            'invoices' => $this->syncInvoices($from),
            'transactions' => $this->syncTransactions($from),
            'payouts' => $this->syncPayouts($from),
            'subscriptions' => $this->syncSubscriptions(),
        ];

        Setting::put('last_sync_at', $startedAt->toIso8601String());
        self::forgetBalance();

        return $counts;
    }

    /** Keep the reporting currency, account name and mode in step with the Stripe account. */
    public function syncAccount(): void
    {
        $account = $this->stripe()->accounts->retrieve();

        if ($account->default_currency) {
            Setting::put('currency', strtolower($account->default_currency));
        }

        Setting::put('stripe_account_name', $account->settings?->dashboard?->display_name ?: $account->email ?: $account->id);
        Setting::put('stripe_mode', self::isLive() ? 'live' : 'test');

        // The fixed part of the bank statement text is set in the Stripe Dashboard.
        $prefix = $account->settings?->card_payments?->statement_descriptor_prefix
            ?: substr((string) ($account->settings?->payments?->statement_descriptor ?? ''), 0, 10);
        Setting::put('descriptor_prefix', $prefix ?: null);

        // Standard card pricing for the account's country, until the owner sets their own.
        if (Setting::get('card_fee_percent') === null) {
            [$percent, $fixed] = match ($account->country) {
                'SG' => [3.4, 50],
                'MY' => [3.0, 100],
                'AU' => [1.75, 30],
                'GB' => [1.5, 20],
                default => [2.9, 30],
            };
            Setting::put('card_fee_percent', (string) $percent);
            Setting::put('card_fee_fixed', (string) $fixed);
        }
    }

    public function syncInvoices(Carbon $from): int
    {
        $n = 0;
        foreach ($this->stripe()->invoices->all(['limit' => 100, 'created' => ['gte' => $from->timestamp]])->autoPagingIterator() as $si) {
            $this->upsertInvoice($si);
            $n++;
        }

        // Open invoices created earlier can still have been paid since.
        Invoice::whereIn('status', ['open', 'draft'])->where('issued_at', '<', $from)->each(function (Invoice $inv) {
            $this->refreshInvoice($inv);
        });

        return $n;
    }

    public function syncTransactions(Carbon $from): int
    {
        $n = 0;
        $list = $this->stripe()->balanceTransactions->all([
            'limit' => 100,
            'created' => ['gte' => $from->timestamp],
            'expand' => ['data.source'],
        ]);

        foreach ($list->autoPagingIterator() as $bt) {
            $this->upsertTransaction($bt);
            $n++;
        }

        return $n;
    }

    public function upsertTransaction(BalanceTransaction $bt): Transaction
    {
        $source = $bt->source;
        $sourceId = is_string($source) ? $source : $source?->id;
        $clientId = null;
        $method = null;

        if (is_object($source)) {
            $clientId = ($source->metadata['klok_client_id'] ?? null) ?: null;
            $customer = $source->customer ?? null;
            $customer = is_object($customer) ? $customer->id : $customer;
            if (! $clientId && $customer) {
                $clientId = Client::findByStripeCustomer($customer)?->id;
            }
            $method = $source->payment_method_details->type ?? null;
            if ($method === 'card' && isset($source->payment_method_details->card->brand)) {
                $method = 'card:'.$source->payment_method_details->card->brand;
            }
        }

        return Transaction::updateOrCreate(['stripe_id' => $bt->id], [
            'client_id' => $clientId && Client::whereKey($clientId)->exists() ? $clientId : null,
            'type' => $bt->type,
            'reporting_category' => $bt->reporting_category,
            'payment_method' => $method,
            'amount' => $bt->amount,
            'fee' => $bt->fee,
            'net' => $bt->net,
            'currency' => $bt->currency,
            'description' => $bt->description ? mb_substr($bt->description, 0, 250) : null,
            'source_id' => $sourceId,
            'status' => $bt->status,
            'available_on' => $this->ts($bt->available_on),
            'occurred_at' => $this->ts($bt->created),
        ]);
    }

    /** Heartbeat: payouts of the last two weeks, at most once an hour (each step is told). */
    public function syncRecentPayouts(): int
    {
        if (! self::configured() || ! Cache::add('stripe-payouts-run', 1, now()->addMinutes(55))) {
            return 0;
        }

        return $this->syncPayouts(now()->subDays(14));
    }

    public function syncPayouts(Carbon $from): int
    {
        $n = 0;
        foreach ($this->stripe()->payouts->all(['limit' => 100, 'created' => ['gte' => $from->timestamp]])->autoPagingIterator() as $p) {
            $this->upsertPayout($p);
            $n++;
        }

        return $n;
    }

    public function upsertPayout(\Stripe\Payout $p): Payout
    {
        $before = Payout::where('stripe_payout_id', $p->id)->value('status');
        $payout = Payout::updateOrCreate(['stripe_payout_id' => $p->id], [
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'method' => $p->method,
            'destination' => is_string($p->destination) ? $p->destination : $p->destination?->id,
            'arrival_at' => $this->ts($p->arrival_date),
            'occurred_at' => $this->ts($p->created),
        ]);

        Ledger::recordPayout($payout); // lands in the chosen bank account, minus its fee

        // Tell about this payout's steps (only recent ones, never a first sync's old history).
        if ($payout->status !== $before && $payout->occurred_at?->gt(now()->subDays(14))) {
            $money = Notify::money($payout->amount, strtoupper($payout->currency));
            $step = match ($payout->status) {
                'pending', 'in_transit' => ['💸 Stripe payout on its way: '.$money, 'To Airwallex, arriving about '.$payout->arrival_at?->format('D j M').'.'],
                'paid' => ['💸 Stripe says the payout is paid: '.$money, 'Airwallex should show it now — it is matched when it lands.'],
                'failed', 'canceled' => ['⚠️ Stripe payout '.$payout->status.': '.$money, 'The money went back to the Stripe balance — check the bank details in Stripe.'],
                default => null,
            };
            if ($step) {
                app(Notify::class)->send('billing', $step[0], $step[1], route('payouts.index'), 'klok-payout');
            }
        }

        return $payout;
    }

    public function syncSubscriptions(): int
    {
        $n = 0;
        RecurringPlan::whereNotIn('status', ['canceled', 'incomplete_expired'])->each(function (RecurringPlan $plan) use (&$n) {
            $this->upsertSubscription($this->stripe()->subscriptions->retrieve($plan->stripe_subscription_id));
            $n++;
        });

        return $n;
    }

    private function ts(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
