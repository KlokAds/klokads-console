<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_clients_invoices_and_money(): void
    {
        $client = Client::create(['name' => 'Hor Wei Kang', 'company' => 'Tasfia Engineering', 'email' => 'hor@example.sg']);
        Invoice::create(['stripe_invoice_id' => 'in_1', 'client_id' => $client->id, 'number' => 'KA-0042', 'currency' => 'sgd', 'status' => 'paid', 'total' => 51679, 'issued_at' => now()]);
        $wallet = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'BDT']);
        Ledger::save(['kind' => 'expense', 'account_id' => $wallet->id, 'amount' => 250000, 'category' => 'food', 'counterparty' => 'Tasfia Bakery', 'occurred_on' => now()->toDateString()]);

        $res = $this->actingAs(User::factory()->create())->getJson('/search?q=tasfia')->assertOk();

        $groups = collect($res->json('groups'))->keyBy('label');
        $this->assertSame('Tasfia Engineering', $groups['Clients']['items'][0]['title']);
        $this->assertStringStartsWith('KA-0042 · Tasfia Engineering', $groups['Invoices']['items'][0]['title'], 'invoices match on client name');
        $this->assertSame('Tasfia Bakery', $groups['Money']['items'][0]['title']);
        $this->assertSame('personal', $groups['Money']['items'][0]['scope']);
        $this->assertStringContainsString('q=tasfia', $groups['Money']['items'][0]['url']);
    }

    public function test_short_or_odd_queries_are_safe(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/search?q=a')->assertOk()->assertExactJson(['groups' => []]);
        $this->getJson('/search?q='.urlencode('50%_x'))->assertOk()->assertJsonPath('groups', []);
    }

    public function test_search_needs_login(): void
    {
        $this->getJson('/search?q=tasfia')->assertUnauthorized();
    }
}
