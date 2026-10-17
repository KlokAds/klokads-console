<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\StripeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds the data for an invoice or receipt PDF and renders it with one of the templates.
 */
class InvoiceDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $template, public string $type, public array $data) {}

    public static function for(Invoice $invoice, ?string $template = null, ?string $type = null): self
    {
        $invoice->loadMissing('client');
        $template = isset(Catalog::TEMPLATES[$template]) ? $template : ($invoice->template ?: Business::templateFor($invoice->service));
        $type = in_array($type, ['invoice', 'receipt'], true) ? $type : ($invoice->status === 'paid' ? 'receipt' : 'invoice');

        try {
            $payment = app(StripeService::class)->paymentInfo($invoice);
        } catch (Throwable) {
            $payment = ['paid_with' => $invoice->paid_with, 'charge_id' => $invoice->charge_id];
        }

        $details = $invoice->details ?? [];
        $lines = collect($invoice->lines ?? [])->map(fn ($l) => [
            'description' => $l['description'] ?? '',
            'quantity' => $l['quantity'] ?? 1,
            'amount' => (int) ($l['amount'] ?? 0),
        ])
            // Main items first; the card fee and any GST line go last.
            ->sortBy(fn ($l) => preg_match('/processing fee|^gst\b/i', $l['description']) ? 1 : 0)
            ->values()->all();

        if (! $lines) {
            $lines = [['description' => $invoice->description ?: 'Services', 'quantity' => 1, 'amount' => (int) ($invoice->subtotal ?? $invoice->total)]];
        }

        $subtotal = (int) ($invoice->subtotal ?? collect($lines)->sum('amount'));

        return new self($template, $type, [
            'business' => Business::profile(),
            'gst' => Business::gst() + ['charged' => $invoice->tax_amount > 0],
            'client' => $invoice->client,
            'number' => $invoice->number ?: 'DRAFT',
            'currency' => strtoupper($invoice->currency),
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
            'paid_at' => $invoice->paid_at,
            'status' => $invoice->status,
            'is_paid' => $invoice->status === 'paid',
            'auto_charge' => $invoice->collection_method === 'charge_automatically',
            'service' => Catalog::SERVICES[$invoice->service] ?? 'Services',
            'description' => $invoice->description,
            'lines' => $lines,
            'campaigns' => $details['campaigns'] ?? [],
            'ad_account_id' => $details['ad_account_id'] ?? null,
            'period_start' => isset($details['period_start']) ? Carbon::parse($details['period_start']) : null,
            'period_end' => isset($details['period_end']) ? Carbon::parse($details['period_end']) : null,
            'subtotal' => $subtotal,
            'tax' => (int) $invoice->tax_amount,
            'total' => (int) $invoice->total,
            'amount_paid' => (int) $invoice->amount_paid,
            'amount_due' => (int) $invoice->amount_remaining,
            'paid_with' => $payment['paid_with'],
            'transaction_id' => $payment['charge_id'],
            'pay_url' => $invoice->status === 'open' ? $invoice->publicUrl() : null,
        ]);
    }

    /** Sample data for previewing templates in Settings. */
    public static function sample(string $template, string $type): self
    {
        $period = now()->subMonthNoOverflow();

        return new self($template, $type, [
            'business' => Business::profile(),
            'gst' => Business::gst() + ['charged' => Business::gstEnabled()],
            'client' => (object) ['name' => 'Jane Tan', 'company' => 'Sample Client Pte Ltd', 'email' => 'jane@example.com', 'country' => 'SG', 'address' => '30 Roberts Ln
#02-01
Singapore 218309', 'tax_id' => '201912345K', 'id' => 1024],
            'number' => 'KA-0001',
            'currency' => strtoupper((string) Setting::get('currency', 'sgd')),
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'paid_at' => $type === 'receipt' ? now() : null,
            'status' => $type === 'receipt' ? 'paid' : 'open',
            'is_paid' => $type === 'receipt',
            'auto_charge' => true,
            'service' => 'Meta Ads',
            'description' => 'Ads budget',
            'lines' => [
                ['description' => 'Meta Ads budget', 'quantity' => 1, 'amount' => 50000],
                ['description' => 'Card processing fee', 'quantity' => 1, 'amount' => 1679],
            ],
            'campaigns' => [
                ['name' => 'Sales Campaign — October', 'detail' => 'Sales Ad Set — Singapore', 'quantity' => 16091, 'unit' => 'Impressions', 'amount' => 18000],
                ['name' => 'Sales Campaign — October', 'detail' => 'Retargeting Ad Set', 'quantity' => 9412, 'unit' => 'Impressions', 'amount' => 12000],
                ['name' => 'Leads Campaign', 'detail' => 'Lead Form Ad Set', 'quantity' => 1151, 'unit' => 'Clicks', 'amount' => 20050],
                ['name' => 'Invalid activity — Leads Campaign (Sep)', 'detail' => null, 'quantity' => null, 'unit' => null, 'amount' => -50],
            ],
            'ad_account_id' => '837982438493101',
            'period_start' => $period->copy()->startOfMonth(),
            'period_end' => $period->copy()->endOfMonth(),
            'subtotal' => 51679,
            'tax' => Business::gstEnabled() ? (int) round(51679 * Business::gstRate() / 100) : 0,
            'total' => 51679 + (Business::gstEnabled() ? (int) round(51679 * Business::gstRate() / 100) : 0),
            'amount_paid' => $type === 'receipt' ? 51679 : 0,
            'amount_due' => $type === 'receipt' ? 0 : 51679,
            'paid_with' => $type === 'receipt' ? 'Visa ···· 4242' : null,
            'transaction_id' => $type === 'receipt' ? 'ch_3SAMPLE0000000000000' : null,
            'pay_url' => null,
        ]);
    }

    public function filename(): string
    {
        return ($this->type === 'receipt' ? 'Receipt' : 'Invoice').'-'.$this->data['number'].'.pdf';
    }

    public function html(): string
    {
        $currency = $this->data['currency'];
        $symbols = ['SGD' => 'S$', 'USD' => '$', 'MYR' => 'RM', 'AUD' => 'A$', 'EUR' => '€', 'GBP' => '£'];

        return view("documents.{$this->template}", $this->data + [
            'type' => $this->type,
            // "SGD 1,890.29" (Google style) and "S$1,890.29" (Meta style)
            'money' => fn (int $cents) => ($cents < 0 ? '-' : '').$currency.' '.number_format(abs($cents) / 100, 2),
            'cash' => fn (int $cents) => ($cents < 0 ? '-' : '').($symbols[$currency] ?? $currency.' ').number_format(abs($cents) / 100, 2),
        ])->render();
    }

    public function pdf(): \Barryvdh\DomPDF\PDF
    {
        // Inline PHP is only used by our own templates for page numbers; no user HTML is rendered.
        return Pdf::setOption(['isPhpEnabled' => true, 'isRemoteEnabled' => false])
            ->loadHTML($this->html())->setPaper('a4');
    }
}
