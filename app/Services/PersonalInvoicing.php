<?php

namespace App\Services;

use App\Mail\PersonalInvoiceMail;
use App\Models\MoneyTransaction;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Models\PersonalRecurring;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\Ledger;
use App\Support\MailSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** My own invoices: numbering, payments, the client's "I've paid", email + PDF, monthly runs. */
class PersonalInvoicing
{
    public function __construct(private Notify $notify) {}

    /** Who the invoice is from, and how to pay. */
    public static function profile(): array
    {
        return [
            'name' => Setting::get('personal_name') ?: (auth()->user()?->name ?? 'Me'),
            'email' => Setting::get('personal_email'),
            'phone' => Setting::get('personal_phone'),
            'address' => Setting::get('personal_address'),
            'methods' => json_decode((string) Setting::get('personal_pay_methods'), true) ?: [],
            'qr' => Setting::get('personal_qr'), // data: URL of the Bangla QR image
        ];
    }

    /**
     * @param  array<int, array{description: string, qty: int|float, rate: int}>  $items  rate in cents
     */
    public function create(PersonalClient $client, array $items, CarbonImmutable $issued, ?CarbonImmutable $due, ?string $note = null, ?PersonalRecurring $recurring = null, string $currency = 'BDT'): PersonalInvoice
    {
        return DB::transaction(function () use ($client, $items, $issued, $due, $note, $recurring, $currency) {
            $next = (int) PersonalInvoice::lockForUpdate()->max('id') + 1;

            return PersonalInvoice::create([
                'number' => 'INV-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'personal_client_id' => $client->id,
                'personal_recurring_id' => $recurring?->id,
                'currency' => $currency,
                'items' => array_values($items),
                'total' => self::total($items),
                'issued_on' => $issued->toDateString(),
                'due_on' => $due?->toDateString(),
                'public_token' => Str::random(40),
                'note' => $note,
            ]);
        });
    }

    public static function total(array $items): int
    {
        return (int) round(collect($items)->sum(fn ($i) => (float) $i['qty'] * (int) $i['rate']));
    }

    /** Money received: record it, put it in the account, update the invoice. */
    public function recordPayment(PersonalInvoice $invoice, int $amount, string $paidOn, string $method, ?string $reference, ?int $accountId, string $source = 'manual'): PersonalInvoicePayment
    {
        $payment = DB::transaction(function () use ($invoice, $amount, $paidOn, $method, $reference, $accountId, $source) {
            $payment = $invoice->payments()->create(compact('amount', 'method', 'reference', 'source') + ['paid_on' => $paidOn, 'account_id' => $accountId]);

            if ($accountId) {
                $tx = Ledger::save([
                    'kind' => 'income', 'account_id' => $accountId, 'amount' => $amount, 'category' => 'work',
                    'counterparty' => $invoice->client->company ?: $invoice->client->name,
                    'description' => "{$invoice->number} · ".(Catalog::PAY_METHODS[$method] ?? $method).($reference ? " {$reference}" : ''),
                    'occurred_on' => $paidOn,
                ]);
                $payment->update(['money_transaction_id' => $tx->id]);
            }

            $this->refresh($invoice);

            return $payment;
        });

        $fresh = $invoice->fresh('client');
        $this->tell($fresh->status === 'paid' ? "✅ {$fresh->number} paid" : "💰 Part payment on {$fresh->number}",
            ($fresh->client->company ?: $fresh->client->name).': '.$this->money($amount, $fresh->currency).' via '.(Catalog::PAY_METHODS[$method] ?? $method)
            .($fresh->due() ? ' · '.$this->money($fresh->due(), $fresh->currency).' still due' : ''));

        return $payment;
    }

    /**
     * Money that already came in (e.g. the bank SMS added it) is this invoice's payment:
     * the entry is kept as it is and only marked as work income for this client.
     */
    public function linkEntry(PersonalInvoice $invoice, MoneyTransaction $tx): PersonalInvoicePayment
    {
        $invoice->loadMissing('client');
        $provider = $tx->account?->provider;
        $method = array_key_exists((string) $provider, Catalog::PAY_METHODS) ? $provider : 'bank';

        $payment = DB::transaction(function () use ($invoice, $tx, $method) {
            $tx->update(['category' => 'work', 'counterparty' => $invoice->client->company ?: $invoice->client->name,
                'description' => "{$invoice->number} · ".ltrim((string) preg_replace('/^Auto · /', '', (string) $tx->description))]);
            $payment = $invoice->payments()->create([
                'amount' => min($tx->amount - $tx->fee, max(0, $invoice->due())), 'paid_on' => $tx->occurred_on->toDateString(), 'method' => $method,
                'account_id' => $tx->account_id, 'money_transaction_id' => $tx->id, 'source' => 'linked',
            ]);
            $this->refresh($invoice);

            return $payment;
        });

        $fresh = $invoice->fresh();
        $this->tell($fresh->status === 'paid' ? "✅ {$fresh->number} paid" : "💰 Part payment on {$fresh->number}",
            ($invoice->client->company ?: $invoice->client->name).': '.$this->money($payment->amount, $fresh->currency).($fresh->due() ? ' · '.$this->money($fresh->due(), $fresh->currency).' still due' : ''));

        return $payment;
    }

    public function removePayment(PersonalInvoicePayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $tx = $payment->money_transaction_id ? MoneyTransaction::find($payment->money_transaction_id) : null;
            if ($tx && $payment->source === 'linked') {
                $tx->update(['category' => 'other_income']); // the money really came in — keep it
            } elseif ($tx) {
                Ledger::delete($tx);
            }
            $invoice = $payment->invoice;
            $payment->delete();
            $this->refresh($invoice);
        });
    }

    /** Re-total payments and set the status. */
    public function refresh(PersonalInvoice $invoice): void
    {
        if ($invoice->status === 'void') {
            return;
        }
        $paid = (int) $invoice->payments()->sum('amount');
        $last = $invoice->payments()->max('paid_on');
        $invoice->update([
            'paid' => $paid,
            'status' => $paid <= 0 ? 'unpaid' : ($paid >= $invoice->total ? 'paid' : 'partial'),
            'paid_on' => $paid >= $invoice->total ? $last : null,
            'reported' => $paid >= $invoice->total ? null : $invoice->reported,
        ]);
    }

    /** The client tapped "I've paid": keep it on hold until confirmed. */
    public function report(PersonalInvoice $invoice, array $data): void
    {
        $invoice->update(['reported' => $data + ['at' => now()->toIso8601String()]]);
        $this->tell("💬 {$invoice->number}: client says paid",
            ($invoice->client->company ?: $invoice->client->name).' · '.$this->money((int) $data['amount'], $invoice->currency)
            .' via '.(Catalog::PAY_METHODS[$data['method']] ?? $data['method']).(! empty($data['reference']) ? " · {$data['reference']}" : '').' — confirm when it arrives.');
    }

    public function send(PersonalInvoice $invoice): void
    {
        if (! $invoice->client->email) {
            throw new RuntimeException('This client has no email address.');
        }
        if (! MailSettings::configured()) {
            throw new RuntimeException('Set up email in Settings → Email first.');
        }

        Mail::to($invoice->client->email)->send(new PersonalInvoiceMail($invoice));
        $invoice->update(['sent_at' => now()]);
    }

    public function pdf(PersonalInvoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('personal.invoice-pdf', ['invoice' => $invoice->loadMissing('client', 'payments'), 'me' => self::profile()])->setPaper('a4');
    }

    /**
     * Monthly jobs: a few days (lead_days) before each pay day, make the invoice for the
     * month just worked (e.g. 9 Oct – 8 Nov, due 9 Nov) and tell me to send it.
     */
    public function runRecurring(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $made = 0;

        foreach (PersonalRecurring::with('client')->where('active', true)->get() as $r) {
            $payDay = CarbonImmutable::parse($r->next_on);
            if ($payDay->subDays($r->lead_days)->gt($today)) {
                continue;
            }

            $period = self::period($payDay, $r->day);
            $items = collect($r->items)->map(fn ($i) => [...$i, 'description' => $i['description'].' ('.$period.')'])->all();
            $invoice = $this->create($r->client, $items, $today, $payDay->lt($today) ? $today : $payDay, $r->note, $r, $r->currency);
            $made++;
            if ($r->auto_send && $r->client->email) {
                try {
                    $this->send($invoice);
                } catch (Throwable) {
                    // stays unsent; the reminder says so
                }
            }
            $r->update(['next_on' => self::nextOn($payDay, $r->day)->toDateString()]);

            $who = $r->client->company ?: $r->client->name;
            $this->notify->send('invoice', $invoice->sent_at ? "🧾 {$who} invoice sent" : "🧾 {$who} invoice ready — send it",
                "{$invoice->number} · ".$this->money($invoice->total, $invoice->currency)." · {$period} · pay day ".$payDay->format('j M'),
                route('personal.invoices.index', ['invoice' => $invoice->id]), 'klok-pinv');
        }

        return $made;
    }

    /** The first pay day on or after $from for a job paid on $day each month. */
    public static function payDayFrom(CarbonInterface $from, int $day): CarbonImmutable
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $candidate = $from->setDay(min($day, $from->daysInMonth));

        return $candidate->lt($from) ? self::nextOn($from, $day) : $candidate;
    }

    /** "9 Oct – 8 Nov": the month of work paid on $payDay. */
    public static function period(CarbonInterface $payDay, int $day): string
    {
        $start = CarbonImmutable::parse($payDay)->startOfMonth()->subMonth();
        $start = $start->setDay(min($day, $start->daysInMonth));

        return $start->format('j M').' – '.CarbonImmutable::parse($payDay)->subDay()->format('j M Y');
    }

    /** Same day next month (or the month's last day). */
    public static function nextOn(CarbonInterface $from, int $day): CarbonImmutable
    {
        $month = CarbonImmutable::parse($from)->startOfMonth()->addMonth();

        return $month->setDay(min($day, $month->daysInMonth));
    }

    private function tell(string $title, string $body): void
    {
        $this->notify->send('invoice', $title, $body, route('personal.invoices.index'), 'klok-pinv');
    }

    private function money(int $cents, string $currency): string
    {
        return ($currency === 'BDT' ? '৳' : $currency.' ').number_format($cents / 100, $currency === 'BDT' ? 0 : 2);
    }
}
