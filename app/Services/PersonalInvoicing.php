<?php

namespace App\Services;

use App\Mail\PersonalInvoiceMail;
use App\Models\MoneyTransaction;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\PersonalInvoicePayment;
use App\Models\PersonalRecurring;
use App\Models\Setting;
use App\Models\User;
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
            // My name from the profile; else my account's (also on the public page, where nobody is signed in).
            'name' => Setting::get('personal_name') ?: (auth()->user()?->name ?? User::where('is_owner', true)->value('name') ?? 'Invoice'),
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

    /**
     * Email it: to, cc, subject and message as I wrote them in the send window (or the defaults),
     * with the PDF attached and/or the link to the pay page.
     *
     * @param  array{to?: string, cc?: array<int, string>, subject?: string, body?: string, attach?: bool, link?: bool}  $mail
     */
    public function send(PersonalInvoice $invoice, array $mail = []): void
    {
        $to = $mail['to'] ?? $invoice->client->email;
        if (! $to) {
            throw new RuntimeException('This client has no email address.');
        }
        if (! MailSettings::configured()) {
            throw new RuntimeException('Set up email in Settings → Email first.');
        }

        Mail::to($to)->cc(array_filter($mail['cc'] ?? []))->send(new PersonalInvoiceMail($invoice, $mail['subject'] ?? null, $mail['body'] ?? null, $mail['attach'] ?? true, $mail['link'] ?? true));
        $invoice->update(['sent_at' => now(), 'sent_via' => 'email', 'sent_to' => $to]);
    }

    /**
     * What the send window starts with: a proper subject and message for email, a message for
     * WhatsApp, the client's number in WhatsApp's form, and the links.
     *
     * @return array<string, mixed>
     */
    public function compose(PersonalInvoice $invoice): array
    {
        $invoice->loadMissing('client');
        $me = self::profile();
        $name = $invoice->client->company ?: $invoice->client->name;
        $amount = $this->money($invoice->due() ?: $invoice->total, $invoice->currency);
        $due = $invoice->due_on ? $invoice->due_on->format('j M Y') : null;
        $what = collect($invoice->items)->pluck('description')->first();
        $url = $invoice->publicUrl();
        $body = "Dear {$name},\n\n"
            ."Please find invoice {$invoice->number}".($what ? " for {$what}" : '')." — {$amount}".($due ? ", due on {$due}" : '').".\n\n"
            ."You can pay by bKash, Nagad, bank transfer or Bangla QR. After paying, please tap “I've paid” on the invoice page so I can confirm it.\n\n"
            ."Thank you for working with me.\n\nBest regards,\n{$me['name']}".($me['phone'] ? "\n{$me['phone']}" : '');
        $whatsapp = "Assalamu Alaikum {$name},\n\n"
            ."Invoice *{$invoice->number}*".($what ? " — {$what}" : '')."\n"
            ."Amount: *{$amount}*".($due ? "\nDue: {$due}" : '')."\n\n"
            ."View & pay: {$url}\n\n"
            ."bKash, Nagad, bank or Bangla QR — after paying, please tap “I've paid” on the page.\n"
            ."Thank you! 🙏\n— {$me['name']}";
        $digits = preg_replace('/\D/', '', (string) $invoice->client->phone);

        return [
            'from' => ['name' => $me['name'], 'address' => config('mail.from.address'), 'reply_to' => $me['email']],
            'to' => $invoice->client->email,
            'subject' => "Invoice {$invoice->number} from {$me['name']} — {$amount}",
            'body' => $body,
            'whatsapp' => $whatsapp,
            // 01XXXXXXXXX → 8801XXXXXXXXX, as WhatsApp wants it
            'phone' => $digits === '' ? null : (str_starts_with($digits, '01') && strlen($digits) === 11 ? '88'.$digits : $digits),
            'url' => $url,
            'pdf' => route('personal.invoice.public.pdf', $invoice->public_token),
            'file' => "{$invoice->number}.pdf",
            'mail_ready' => MailSettings::configured(),
            'sent' => $invoice->sent_at ? ['at' => $invoice->sent_at->toIso8601String(), 'via' => $invoice->sent_via, 'to' => $invoice->sent_to] : null,
        ];
    }

    /** Sent by WhatsApp (from my phone): noted on the invoice. */
    public function sharedOnWhatsApp(PersonalInvoice $invoice, ?string $to): void
    {
        $invoice->update(['sent_at' => $invoice->sent_at ?? now(), 'sent_via' => 'whatsapp', 'sent_to' => $to]);
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
        return ($currency === 'BDT' ? '৳' : $currency.' ').number_format($cents / 100, 2);
    }
}
