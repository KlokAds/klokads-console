<?php

namespace App\Mail;

use App\Models\ClientStatement;
use App\Services\AdStatements;
use App\Support\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A prepaid client's ad statement: balance, top-ups and what each campaign spent. */
class StatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ClientStatement $statement) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ad statement — '.$this->period().' · '.Business::profile()['name'],
            replyTo: array_filter([Business::profile()['email']]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.statement', with: [
            'd' => $this->statement->data,
            'period' => $this->period(),
            'lines' => AdStatements::lines($this->statement->data, $this->statement->show_fee),
        ]);
    }

    private function period(): string
    {
        $s = $this->statement;

        return $s->period_start->isSameDay($s->period_start->copy()->startOfMonth()) && $s->period_end->isSameDay($s->period_start->copy()->endOfMonth())
            ? $s->period_start->format('F Y')
            : $s->period_start->format('j M').' – '.$s->period_end->format('j M Y');
    }
}
