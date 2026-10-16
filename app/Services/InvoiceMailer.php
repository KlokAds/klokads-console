<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends our own invoice and receipt emails (never Stripe's).
 */
class InvoiceMailer
{
    public function sendInvoice(Invoice $invoice): void
    {
        $this->send($invoice, 'invoice');
        $invoice->forceFill(['invoice_sent_at' => now()])->saveQuietly();
    }

    public function sendReceipt(Invoice $invoice): void
    {
        $this->send($invoice, 'receipt');
        $invoice->forceFill(['receipt_sent_at' => now()])->saveQuietly();
    }

    /**
     * Called whenever an invoice is saved from Stripe: email a receipt when it has just
     * been paid, and the invoice when a recurring "emailed invoice" cycle starts.
     * History imported by a sync never triggers emails.
     */
    public function afterSync(?Invoice $before, Invoice $after): void
    {
        if (! MailSettings::configured()) {
            return;
        }

        $settings = MailSettings::current();
        $justPaid = $after->status === 'paid' && ($before
            ? $before->status !== 'paid'
            : $after->paid_at && $after->paid_at->gt(now()->subMinutes(15)));

        $newCycleInvoice = ! $before && $after->status === 'open' && $after->stripe_subscription_id
            && $after->collection_method === 'send_invoice' && $after->issued_at?->gt(now()->subDay());

        try {
            if ($justPaid && $settings['auto_receipts'] && ! $after->receipt_sent_at) {
                $this->sendReceipt($after);
            } elseif ($newCycleInvoice && $settings['auto_invoices'] && ! $after->invoice_sent_at) {
                $this->sendInvoice($after);
            }
        } catch (Throwable $e) {
            Log::warning("Could not email {$after->number}: ".$e->getMessage());
        }
    }

    private function send(Invoice $invoice, string $kind): void
    {
        if (! MailSettings::configured()) {
            throw new RuntimeException('Email is not set up yet — add your SMTP details in Settings → Email.');
        }

        $invoice->loadMissing('client');
        $to = $invoice->client?->email;
        if (! $to) {
            throw new RuntimeException('This invoice has no client email address.');
        }

        try {
            Mail::to($to, $invoice->client->company ?: $invoice->client->name)->send(new InvoiceMail($invoice, $kind));
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('The email server refused the message: '.$e->getMessage(), previous: $e);
        }
    }
}
