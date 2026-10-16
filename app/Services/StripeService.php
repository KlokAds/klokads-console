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
use App\Support\Pricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Stripe\BalanceTransaction;
use Stripe\StripeClient;
use Stripe\Subscription;

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
        $cards = $stripe->customers->allPaymentMethods($customerId, ['type' => 'card', 'limit' => 20])->data;
        $default = $stripe->customers->retrieve($customerId)->invoice_settings?->default_payment_method;
        $default = is_object($default) ? $default->id : $default;

        if (! $default && count($cards) > 0) {
            $default = $cards[0]->id;
            $this->setDefaultPaymentMethod($client, $default);
        }

        return collect($cards)
            ->map(fn ($pm) => [
                'id' => $pm->id,
                'brand' => $pm->card->brand,
                'last4' => $pm->card->last4,
                'exp_month' => $pm->card->exp_month,
                'exp_year' => $pm->card->exp_year,
                'is_default' => $pm->id === $default,
            ])
            ->sortByDesc('is_default')->values()->all();
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

    public function removePaymentMethod(Client $client, string $paymentMethodId): void
    {
        $this->assertOwnsPaymentMethod($client, $paymentMethodId);
        $wasDefault = collect($this->paymentMethods($client))->firstWhere('id', $paymentMethodId)['is_default'] ?? false;

        $this->stripe()->paymentMethods->detach($paymentMethodId);

        if ($wasDefault && ($next = $this->paymentMethods($client)[0]['id'] ?? null)) {
            $this->setDefaultPaymentMethod($client, $next);
        }
    }

    /** A secure Stripe page where the client saves a new card (valid 24 hours). */
    public function cardSetupLink(Client $client, string $returnUrl): string
    {
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $this->ensureCustomer($client),
            'currency' => $this->currency(),
            'payment_method_types' => ['card'],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => ['klok_client_id' => $client->id],
        ]);

        return $session->url;
    }

    /**
     * Charge a saved card right away for any amount. Creates a finalized invoice so the
     * charge has a number, a receipt and shows up in every total.
     */
    public function chargeNow(Client $client, int $amount, string $description, ?string $service = null, ?string $paymentMethodId = null, ?array $budget = null, ?string $statementText = null): Invoice
    {
        $items = $budget
            ? self::budgetItems($budget, $description)
            : [['description' => $description, 'amount' => $amount, 'quantity' => 1]];
        $stripe = $this->stripe();
        $customer = $this->ensureCustomer($client);
        $paymentMethodId ??= collect($this->paymentMethods($client))->firstWhere('is_default', true)['id'] ?? null;

        if (! $paymentMethodId) {
            throw new RuntimeException('This client has no saved card yet.');
        }

        $this->assertOwnsPaymentMethod($client, $paymentMethodId);

        $invoice = $stripe->invoices->create([
            'customer' => $customer,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'currency' => $this->currency(),
            'description' => $description,
            'pending_invoice_items_behavior' => 'exclude',
            'metadata' => ['klok_client_id' => $client->id, 'service' => $service ?? '', 'charged_from' => 'klokads-console'] + self::budgetMetadata($budget),
            'statement_descriptor' => self::cleanDescriptor($statementText) ?? self::descriptorFor($service, now()),
        ] + $this->taxParams());

        foreach ($items as $item) {
            $stripe->invoiceItems->create([
                'customer' => $customer,
                'invoice' => $invoice->id,
                'currency' => $this->currency(),
                'amount' => $item['amount'],
                'description' => $item['description'],
            ]);
        }

        $stripe->invoices->finalizeInvoice($invoice->id, ['auto_advance' => false]);

        return $this->upsertInvoice($stripe->invoices->pay($invoice->id, ['payment_method' => $paymentMethodId]));
    }

    /** Charge an open invoice to a saved card (the default when none is given). */
    public function chargeInvoice(Invoice $invoice, ?string $paymentMethodId = null): Invoice
    {
        if ($paymentMethodId && $invoice->client) {
            $this->assertOwnsPaymentMethod($invoice->client, $paymentMethodId);
        }

        $params = $paymentMethodId ? ['payment_method' => $paymentMethodId] : [];

        return $this->upsertInvoice($this->stripe()->invoices->pay($invoice->stripe_invoice_id, $params));
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
    public const BUDGET_FIELDS = ['net_amount', 'processing_fee', 'commission_rate', 'commission_amount', 'ad_budget'];

    /**
     * Budget line plus the card processing fee line the client pays on top.
     *
     * @param  array{net: int, processing_fee: int}  $budget
     * @return array<int, array{description: string, amount: int, quantity: int}>
     */
    public static function budgetItems(array $budget, string $label): array
    {
        $items = [['description' => $label, 'amount' => $budget['net'], 'quantity' => 1]];

        if ($budget['processing_fee'] > 0) {
            $items[] = ['description' => 'Card processing fee', 'amount' => $budget['processing_fee'], 'quantity' => 1];
        }

        return $items;
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
        ];
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
    public function createInvoice(Client $client, array $items, array $opts): Invoice
    {
        $stripe = $this->stripe();
        $currency = $opts['currency'] ?? $this->currency();

        $invoice = $stripe->invoices->create([
            'customer' => $this->ensureCustomer($client),
            'collection_method' => 'send_invoice',
            'days_until_due' => $opts['days_until_due'] ?? 7,
            'currency' => $currency,
            'description' => $opts['memo'] ?? null,
            'pending_invoice_items_behavior' => 'exclude',
            'metadata' => ['klok_client_id' => $client->id, 'service' => $opts['service'] ?? ''] + self::budgetMetadata($opts['budget'] ?? null),
            'statement_descriptor' => self::cleanDescriptor($opts['statement_descriptor'] ?? null) ?? self::descriptorFor(
                $opts['service'] ?? null,
                isset($opts['details']['period_start']) ? Carbon::parse($opts['details']['period_start']) : now(),
            ),
        ] + $this->taxParams());

        if (! empty($opts['budget'])) {
            $items = self::budgetItems($opts['budget'], $opts['budget_label'] ?? 'Ads budget');
        }

        foreach ($items as $item) {
            $qty = max(1, (int) $item['quantity']);
            $unit = $item['unit'] ?? null;

            // With a unit (Clicks, Impressions…) the quantity is usage and the amount is the line total.
            $stripe->invoiceItems->create([
                'customer' => $client->stripeCustomerId(),
                'invoice' => $invoice->id,
                'currency' => $currency,
                'amount' => $unit ? $item['amount'] : $item['amount'] * $qty,
                'description' => match (true) {
                    (bool) $unit => "{$item['description']} — ".number_format($qty)." {$unit}",
                    $qty > 1 => "{$item['description']} × {$qty}",
                    default => $item['description'],
                },
            ]);
        }

        $invoice = $stripe->invoices->finalizeInvoice($invoice->id);

        if ($opts['send'] ?? false) {
            $invoice = $stripe->invoices->sendInvoice($invoice->id);
        }

        $local = $this->upsertInvoice($invoice);
        $local->update([
            'template' => $opts['template'] ?? Business::templateFor($opts['service'] ?? null),
            'details' => $opts['details'] ?? null,
        ]);

        return $local;
    }

    /**
     * Card and charge behind a paid invoice, for receipts. Cached on the invoice.
     *
     * @return array{paid_with: ?string, charge_id: ?string}
     */
    public function paymentInfo(Invoice $invoice): array
    {
        if ($invoice->paid_with || $invoice->status !== 'paid' || ! self::configured()) {
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

    public function sendInvoice(Invoice $invoice): Invoice
    {
        return $this->upsertInvoice($this->stripe()->invoices->sendInvoice($invoice->stripe_invoice_id));
    }

    public function voidInvoice(Invoice $invoice): Invoice
    {
        return $this->upsertInvoice($this->stripe()->invoices->voidInvoice($invoice->stripe_invoice_id));
    }

    /** Client paid outside Stripe (bank transfer / PayNow). */
    public function markInvoicePaid(Invoice $invoice): Invoice
    {
        return $this->upsertInvoice(
            $this->stripe()->invoices->pay($invoice->stripe_invoice_id, ['paid_out_of_band' => true])
        );
    }

    public function refreshInvoice(Invoice $invoice): Invoice
    {
        return $this->upsertInvoice($this->stripe()->invoices->retrieve($invoice->stripe_invoice_id));
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

        return Invoice::updateOrCreate(['stripe_invoice_id' => $si->id], $budget + [
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
            // Send-invoice subscriptions: make sure the first invoice goes out by email right away.
            if ($data['collection'] !== 'auto' && $invoice->status === 'draft') {
                $invoice = $stripe->invoices->finalizeInvoice($invoice->id);
                $invoice = $stripe->invoices->sendInvoice($invoice->id);
                $plan->update(['first_invoice_url' => $invoice->hosted_invoice_url]);
            }
            $this->upsertInvoice($invoice);
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
        return Payout::updateOrCreate(['stripe_payout_id' => $p->id], [
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'method' => $p->method,
            'destination' => is_string($p->destination) ? $p->destination : $p->destination?->id,
            'arrival_at' => $this->ts($p->arrival_date),
            'occurred_at' => $this->ts($p->created),
        ]);
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
