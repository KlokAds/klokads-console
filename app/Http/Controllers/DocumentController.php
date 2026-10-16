<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\Catalog;
use App\Support\InvoiceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Our own invoice and receipt PDFs (one of the Catalog::TEMPLATES designs).
 */
class DocumentController extends Controller
{
    public function show(Request $request, Invoice $invoice): Response
    {
        $data = $this->options($request);
        $doc = InvoiceDocument::for($invoice, $data['template'] ?? null, $data['type'] ?? null);

        return $request->boolean('download') ? $doc->pdf()->download($doc->filename()) : $doc->pdf()->stream($doc->filename());
    }

    /** Remember which template this invoice should use. */
    public function setTemplate(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['template' => ['required', Rule::in(array_keys(Catalog::TEMPLATES))]]);
        $invoice->update(['template' => $data['template']]);

        return back()->with('success', 'Template set to '.Catalog::TEMPLATES[$data['template']].'.');
    }

    /** Sample PDF of a template, for choosing in Settings. */
    public function preview(Request $request, string $template): Response
    {
        abort_unless(isset(Catalog::TEMPLATES[$template]), 404);
        $type = $request->input('type') === 'receipt' ? 'receipt' : 'invoice';
        $doc = InvoiceDocument::sample($template, $type);

        return $doc->pdf()->stream("{$template}-{$type}-sample.pdf");
    }

    /** @return array{template?: string, type?: string} */
    private function options(Request $request): array
    {
        return $request->validate([
            'template' => ['nullable', Rule::in(array_keys(Catalog::TEMPLATES))],
            'type' => ['nullable', Rule::in(['invoice', 'receipt'])],
        ]);
    }
}
