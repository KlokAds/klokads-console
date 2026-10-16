<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Payout;
use App\Models\RecurringPlan;
use App\Models\Setting;
use App\Models\Transaction;
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
            'metadata' => ['klok_client_id' => $client->id, 'service' => $opts['service'] ?? ''],
        ]);

        foreach ($items as $item) {
            $qty = max(1, (int) $item['quantity']);
            $stripe->invoiceItems->create([
                'customer' => $client->stripeCustomerId(),
                'invoice' => $invoice->id,
                'currency' => $currency,
                'amount' => $item['amount'] * $qty,
                'description' => $qty > 1 ? "{$item['description']} × {$qty}" : $item['description'],
            ]);
        }

        $invoice = $stripe->invoices->finalizeInvoice($invoice->id);

        if ($opts['send'] ?? false) {
            $invoice = $stripe->invoices->sendInvoice($invoice->id);
        }

        return $this->upsertInvoice($invoice);
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

        return Invoice::updateOrCreate(['stripe_invoice_id' => $si->id], [
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

        $product = $stripe->products->create([
            'name' => $data['description'],
            'metadata' => ['service' => $data['service'] ?? ''],
        ]);

        $params = [
            'customer' => $this->ensureCustomer($client),
            'items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product' => $product->id,
                    'unit_amount' => $data['amount'],
                    'recurring' => ['interval' => $data['interval'], 'interval_count' => $data['interval_count']],
                ],
            ]],
            'description' => $data['description'],
            'metadata' => ['klok_client_id' => $client->id, 'service' => $data['service'] ?? ''],
            'expand' => ['latest_invoice'],
        ];

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

        $plan = RecurringPlan::create([
            'client_id' => $client->id,
            'stripe_subscription_id' => $sub->id,
            'description' => $data['description'],
            'service' => $data['service'] ?? null,
            'amount' => $data['amount'],
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
        $meta = ['klok_client_id' => $client?->id ?? '', 'service' => $data['service'] ?? ''];

        $price = $stripe->prices->create([
            'currency' => $currency,
            'unit_amount' => $data['amount'],
            'product_data' => ['name' => $data['description']],
        ]);

        $link = $stripe->paymentLinks->create([
            'line_items' => [['price' => $price->id, 'quantity' => 1]],
            'metadata' => $meta,
            // Every payment produces an invoice + receipt, so it lands in our invoice totals too.
            'invoice_creation' => ['enabled' => true, 'invoice_data' => ['metadata' => $meta, 'description' => $data['description']]],
            'payment_intent_data' => ['metadata' => $meta, 'description' => $data['description']],
            'customer_creation' => 'always',
        ]);

        return PaymentLink::create([
            'client_id' => $client?->id,
            'stripe_payment_link_id' => $link->id,
            'url' => $link->url,
            'description' => $data['description'],
            'service' => $data['service'] ?? null,
            'amount' => $data['amount'],
            'currency' => $currency,
            'active' => true,
        ]);
    }

    public function setPaymentLinkActive(PaymentLink $link, bool $active): void
    {
        $this->stripe()->paymentLinks->update($link->stripe_payment_link_id, ['active' => $active]);
        $link->update(['active' => $active]);
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

    public function upsertPayout(\Stripe\Payout $p): void
    {
        Payout::updateOrCreate(['stripe_payout_id' => $p->id], [
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
