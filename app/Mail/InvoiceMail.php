<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\Business;
use App\Support\InvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Our own invoice or receipt email, with the PDF in the invoice's template attached
 * and a button to our own invoice page.
 */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $kind = 'invoice') {}

    public function envelope(): Envelope
    {
        $business = Business::profile()['name'];
        $number = $this->invoice->number ?: 'invoice';

        return new Envelope(
            subject: $this->kind === 'receipt'
                ? "Receipt for your payment — {$number} · {$business}"
                : "Invoice {$number} from {$business}",
            replyTo: array_filter([Business::profile()['email']]),
        );
    }

    public function content(): Content
    {
        $doc = InvoiceDocument::for($this->invoice, null, $this->kind);
        $amount = $this->kind === 'receipt' ? $doc->data['amount_paid'] : $doc->data['amount_due'];

        return new Content(view: 'mail.invoice', with: [
            'kind' => $this->kind,
            'd' => $doc->data,
            'amount' => $doc->data['currency'].' '.number_format($amount / 100, 2),
            'url' => $this->invoice->publicUrl(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $doc = InvoiceDocument::for($this->invoice, null, $this->kind);

        return [Attachment::fromData(fn () => $doc->pdf()->output(), $doc->filename())->withMime('application/pdf')];
    }
}
