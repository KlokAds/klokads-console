<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use App\Support\Business;
use App\Support\Catalog;
use App\Support\InvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $attributes = []): Invoice
    {
        $client = Client::create(['name' => 'Jane Tan', 'company' => 'Lion City Bakery', 'email' => 'jane@example.sg']);

        return Invoice::create($attributes + [
            'client_id' => $client->id, 'stripe_invoice_id' => 'in_'.uniqid(), 'number' => 'KA-0042', 'currency' => 'sgd',
            'status' => 'paid', 'total' => 56330, 'subtotal' => 51679, 'tax_amount' => 4651, 'amount_paid' => 56330, 'amount_remaining' => 0,
            'service' => 'meta_ads', 'issued_at' => now(), 'paid_at' => now(), 'paid_with' => 'Visa ···· 4242', 'charge_id' => 'ch_1',
            'lines' => [['description' => 'Meta Ads budget', 'amount' => 50000, 'quantity' => 1], ['description' => 'Card processing fee', 'amount' => 1679, 'quantity' => 1]],
            'details' => ['ad_account_id' => '837982438493101', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'campaigns' => [['name' => 'Sales Campaign', 'detail' => 'Ad set', 'quantity' => 16091, 'unit' => 'Impressions', 'amount' => 50000]]],
        ]);
    }

    public function test_every_template_renders_as_invoice_and_receipt(): void
    {
        Setting::put('business_legal_name', 'Klok Ads Pte. Ltd.');
        $invoice = $this->invoice();

        foreach (array_keys(Catalog::TEMPLATES) as $template) {
            foreach (['invoice', 'receipt'] as $type) {
                $doc = InvoiceDocument::for($invoice, $template, $type);
                $html = $doc->html();

                $this->assertStringContainsString('KA-0042', $html, "{$template}/{$type}");
                $this->assertStringContainsString('Klok Ads Pte. Ltd.', $html, "{$template}/{$type}");
                $this->assertStringContainsString('Sales Campaign', $html, "{$template}/{$type}");
                $this->assertStringNotContainsString('Meta Platforms', $html);
                $this->assertStringNotContainsString('Google Asia Pacific', $html);
                $this->assertStringStartsWith('%PDF', $doc->pdf()->output());
            }
        }
    }

    public function test_gst_shows_on_tax_invoices_only_when_charged(): void
    {
        Setting::put('gst_reg_no', 'M90012345X');
        Setting::put('gst_enabled', '1');
        $html = InvoiceDocument::for($this->invoice(), 'classic_google', 'invoice')->html();
        $this->assertStringContainsString('Tax Invoice', $html);
        $this->assertStringContainsString('GST (9%)', $html);
        $this->assertStringContainsString('M90012345X', $html);

        $plain = InvoiceDocument::for($this->invoice(['tax_amount' => 0, 'total' => 51679, 'number' => 'KA-0043']), 'classic_google', 'invoice')->html();
        $this->assertStringNotContainsString('Tax Invoice', $plain);
    }

    public function test_document_route_needs_login_and_streams_a_pdf(): void
    {
        $invoice = $this->invoice();

        $this->get("/invoices/{$invoice->id}/document")->assertRedirect('/login');

        $response = $this->actingAs(User::factory()->create())->get("/invoices/{$invoice->id}/document?template=pro_meta");
        $response->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs(User::factory()->create())->get('/settings/templates/classic_meta/preview?type=receipt')->assertOk();
        $this->actingAs(User::factory()->create())->get('/settings/templates/nope/preview')->assertNotFound();
    }

    public function test_gst_switch_needs_a_registration_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/settings')->post('/settings/gst', ['enabled' => true, 'rate' => 9, 'label' => 'GST'])
            ->assertSessionHasErrors('reg_no');
        $this->assertFalse(Business::gstEnabled());

        $this->actingAs($user)->post('/settings/gst', ['enabled' => true, 'rate' => 9, 'label' => 'GST', 'reg_no' => 'M90012345X'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Business::gstEnabled());
        $this->assertSame(9.0, Business::activeGstRate());
    }

    public function test_default_templates_per_service_are_saved(): void
    {
        $this->actingAs(User::factory()->create())->post('/settings/templates', ['defaults' => ['meta_ads' => 'pro_meta', 'seo' => 'classic_google']])
            ->assertSessionHasNoErrors();

        $this->assertSame('pro_meta', Business::templateFor('meta_ads'));
        $this->assertSame('classic_google', Business::templateFor('seo'));
        $this->assertSame('classic_google', Business::templateFor('google_ads'));
    }

    public function test_typed_statement_text_reaches_stripe(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg']);

        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('currency')->andReturn('sgd');
            $m->shouldReceive('chargeNow')->once()
                ->withArgs(fn (...$args) => $args[6] === 'META OCT PROM')
                ->andReturn(new Invoice);
        });

        $this->actingAs(User::factory()->create())->from('/clients')->post("/clients/{$client->id}/charge", [
            'amount' => '100', 'description' => 'Promo', 'service' => 'meta_ads', 'confirm' => '1', 'statement_descriptor' => 'META OCT PROMO',
        ])->assertSessionHasErrors('statement_descriptor'); // 14 characters > 13

        $this->actingAs(User::factory()->create())->from('/clients')->post("/clients/{$client->id}/charge", [
            'amount' => '100', 'description' => 'Promo', 'service' => 'meta_ads', 'confirm' => '1', 'statement_descriptor' => 'META OCT PROM',
        ]);
    }
}
