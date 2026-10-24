<?php

namespace App\Mail;

use App\Models\PersonalInvoice;
use App\Services\PersonalInvoicing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A personal invoice by email: link to the pay page, payment details, PDF attached. */
class PersonalInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PersonalInvoice $invoice) {}

    public function envelope(): Envelope
    {
        $me = PersonalInvoicing::profile();

        return new Envelope(
            from: new Address(config('mail.from.address'), $me['name']),
            replyTo: array_filter([$me['email']]),
            subject: "Invoice {$this->invoice->number} from {$me['name']}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.personal-invoice', with: [
            'invoice' => $this->invoice->loadMissing('client'),
            'me' => PersonalInvoicing::profile(),
            'url' => $this->invoice->publicUrl(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => app(PersonalInvoicing::class)->pdf($this->invoice)->output(), "{$this->invoice->number}.pdf")->withMime('application/pdf')];
    }
}
