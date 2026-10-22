<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MetaSpendDay;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prepaid balance for Meta clients.
 *  - Each finished day: balance −= spend + commission (commission is earned then).
 *  - When the balance would last fewer than `prepaid_buffer_days` at the recent pace,
 *    charge the saved card a top-up worth `prepaid_topup_days` (at least the minimum).
 * The buffer is sized so a top-up's ~7-day Stripe payout lands before the money is needed.
 */
class PrepaidBalance
{
    /** A top-up bigger than this many minimums waits for a manual go-ahead. */
    private const SAFETY_MULTIPLE = 10;

    public function __construct(private StripeService $stripe, private SpendBilling $billing) {}

    /** Take every finished, not-yet-deducted day from the balance. Returns days deducted. */
    public function deduct(Client $client): int
    {
        $days = $this->billing->unbilled($client)->groupBy(fn ($d) => $d->date->toDateString());
        if ($days->isEmpty()) {
            return 0;
        }
        $currency = strtolower($days->first()->first()->currency);
        if ($currency !== $this->stripe->currency()) {
            $this->billing->failed($client, 'The ad account is in '.strtoupper($currency).' but the balance is in '.strtoupper($this->stripe->currency()).' — they must match.');

            return 0;
        }

        $rate = Pricing::forClient($client)->commissionRate;
        DB::transaction(function () use ($client, $days, $rate) {
            foreach ($days as $date => $rows) {
                $spend = (int) $rows->sum('spend');
                $commission = (int) round($spend * $rate / 100);
                ClientBalanceEntry::create([
                    'client_id' => $client->id, 'kind' => 'spend', 'date' => $date,
                    'amount' => -($spend + $commission), 'spend' => $spend, 'commission' => $commission,
                    'note' => $rows->count().' campaign'.($rows->count() === 1 ? '' : 's'),
                ]);
                // What we pay Meta for this client, one running expense per month.
                $month = CarbonImmutable::parse($date);
                $expense = Expense::firstOrNew(['reference' => "meta-auto:{$client->id}:".$month->format('Y-m')], [
                    'client_id' => $client->id, 'category' => 'meta_ads', 'vendor' => 'Meta', 'paid_via' => 'card', 'amount' => 0,
                    'description' => 'Ad spend '.$month->format('F Y').' (from Meta, auto)',
                ]);
                $expense->amount += $spend;
                $expense->spent_on = $date;
                $expense->save();
            }
            $client->update(['spend_billed_until' => $days->keys()->last()]);
        });

        return $days->count();
    }

    /**
     * Balance, recent daily cost (spend + commission) and how long it lasts.
     *
     * @return array{balance: int, daily: int, days_left: float|null, buffer: int, topup: int, low: bool}
     */
    public function status(Client $client): array
    {
        $balance = $client->balance();
        $daily = $this->dailyCost($client);
        $buffer = $daily * $client->prepaid_buffer_days;
        $topup = max($client->prepaid_min_topup, $daily * $client->prepaid_topup_days - max(0, $balance));
        $topup = (int) (ceil($topup / 1000) * 1000); // round up to a tidy 10.00

        return [
            'balance' => $balance,
            'daily' => $daily,
            'days_left' => $daily > 0 ? round(max(0, $balance) / $daily, 1) : null,
            'buffer' => $buffer,
            'topup' => $topup,
            'low' => $balance < 0 || ($daily > 0 && $balance < $buffer),
        ];
    }

    /** Average daily cost (spend + commission) over the last 7 finished days. */
    public function dailyCost(Client $client): int
    {
        $today = CarbonImmutable::today();
        $spend = (int) MetaSpendDay::where('client_id', $client->id)
            ->where('date', '>=', $today->subDays(7)->toDateString())->where('date', '<', $today->toDateString())
            ->sum('spend');
        $rate = Pricing::forClient($client)->commissionRate;

        return (int) round($spend / 7 * (1 + $rate / 100));
    }

    /** Charge a top-up when the balance is below the backup. Returns the paid invoice, if any. */
    public function topUpIfLow(Client $client, ?int $amount = null): ?Invoice
    {
        $manual = $amount !== null;
        $status = $this->status($client);

        if (! $manual && (! $client->spend_billing || ! $status['low'])) {
            return null;
        }
        if (! $manual && $client->spend_error && $client->spend_attempted_at?->gt(now()->subDay())) {
            return null; // a declined card is retried once a day
        }

        $amount ??= $status['topup'];
        if (! $manual && $amount > $client->prepaid_min_topup * self::SAFETY_MULTIPLE) {
            return $this->billing->failed($client, 'Top-up of '.number_format($amount / 100, 2).' is unusually large — check the spend and use "Top up now".');
        }

        // Client pays the card fee on top, so the full amount lands in their balance.
        $budget = ['mode' => 'topup', 'commission' => 0, 'ad_budget' => $amount] + Pricing::forClient($client)->breakdown($amount);
        $client->update(['spend_attempted_at' => now()]);

        try {
            $invoice = $this->stripe->chargeNow($client, $budget['charge'], 'Ad balance top-up', 'meta_ads', null, $budget);
        } catch (Throwable $e) {
            return $this->billing->failed($client, 'Top-up charge failed: '.$e->getMessage());
        }

        ClientBalanceEntry::create([
            'client_id' => $client->id, 'kind' => 'top_up', 'amount' => $amount, 'date' => now()->toDateString(),
            'invoice_id' => $invoice->id, 'note' => $invoice->number,
        ]);
        $client->update(['spend_error' => null]);

        $after = $this->status($client->fresh());
        $this->billing->tell('💳 Topped up '.($client->company ?: $client->name),
            $this->money($amount).' added — balance '.$this->money($after['balance']).($after['days_left'] !== null ? ", about {$after['days_left']} days of ads" : '').'.');

        return $invoice->fresh();
    }

    /** Money received another way (bank transfer) or a correction. */
    public function adjust(Client $client, int $amount, string $note): ClientBalanceEntry
    {
        return ClientBalanceEntry::create([
            'client_id' => $client->id, 'kind' => 'manual', 'amount' => $amount, 'date' => now()->toDateString(), 'note' => $note,
        ]);
    }

    /** Hourly: deduct finished days, then top up if needed; warn once when it runs out. */
    public function run(Client $client): ?Invoice
    {
        $this->deduct($client);
        $invoice = $this->topUpIfLow($client->fresh());

        $fresh = $client->fresh();
        $status = $this->status($fresh);
        if (! $invoice && $status['balance'] <= 0 && $status['daily'] > 0 && ! $fresh->spend_error) {
            $this->billing->failed($client, 'Balance is used up ('.$this->money($status['balance']).') — ads are running on our money. Top up or pause the campaigns.');
        }

        return $invoice;
    }

    private function money(int $cents): string
    {
        return strtoupper($this->stripe->currency()).' '.number_format($cents / 100, 2);
    }
}
