<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPrefillTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_invoice_form_gets_each_clients_last_campaigns_without_numbers_or_credits(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        $client = Client::create(['name' => 'Ann', 'email' => 'ann@example.sg', 'country' => 'SG']);
        Invoice::create(['stripe_invoice_id' => 'in_old', 'client_id' => $client->id, 'currency' => 'sgd', 'status' => 'paid', 'total' => 100, 'issued_at' => now()->subMonths(2),
            'details' => ['ad_account_id' => 'OLD', 'campaigns' => [['name' => 'Old', 'amount' => 100]]]]);
        Invoice::create(['stripe_invoice_id' => 'in_new', 'client_id' => $client->id, 'currency' => 'sgd', 'status' => 'paid', 'total' => 100, 'issued_at' => now()->subMonth(),
            'template' => 'classic_google', 'service' => 'google_ads',
            'details' => ['ad_account_id' => '371-940-7297', 'campaigns' => [
                ['name' => 'Handyman', 'detail' => null, 'quantity' => 1151, 'unit' => 'Clicks', 'amount' => 106700],
                ['name' => 'Invalid activity', 'detail' => null, 'quantity' => null, 'unit' => null, 'amount' => -183],
            ]]]);

        $this->actingAs(User::factory()->create())->get('/invoices/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Invoices/Create')
                ->where("lastDetails.{$client->id}.ad_account_id", '371-940-7297')
                ->where("lastDetails.{$client->id}.template", 'classic_google')
                ->where("lastDetails.{$client->id}.campaigns", [['name' => 'Handyman', 'detail' => '', 'unit' => 'Clicks']]));
    }

    public function test_client_address_and_tax_id_are_saved_and_printed(): void
    {
        $this->actingAs(User::factory()->create())->post('/clients', [
            'name' => 'Hor Wei Kang', 'company' => 'Tasfia Engineering', 'email' => 'hor@example.sg', 'country' => 'SG',
            'address' => "30 Roberts Ln\n#02-01\nSingapore 218309", 'tax_id' => '201912345K',
        ]);

        $client = Client::firstWhere('email', 'hor@example.sg');
        $this->assertSame('201912345K', $client->tax_id);

        $google = InvoiceDocument::sample('classic_google', 'invoice')->html();
        $this->assertStringContainsString('30 Roberts Ln<br />', $google);

        $meta = InvoiceDocument::sample('classic_meta', 'invoice')->html();
        $this->assertStringContainsString('Tax ID: 201912345K', $meta);
        $this->assertSame(1, substr_count($meta, 'Sales Campaign — October</div>'), 'ad sets of one campaign share one header');
    }
}
