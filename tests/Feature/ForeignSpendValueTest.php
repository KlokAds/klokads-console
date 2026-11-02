<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Dollars spent from a wallet nobody filled with Singapore dollars: valued at today's rate, shown in dollars. */
class ForeignSpendValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dollar_card_payment_is_worth_its_market_rate_and_keeps_its_dollars(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['SGD' => 1.3]])]);
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $copy = Account::create(['name' => 'nSave Copy', 'scope' => 'business', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD', 'opening_balance' => 12066]);

        $tx = Ledger::save(['kind' => 'expense', 'account_id' => $copy->id, 'amount' => 1282, 'category' => 'meta_ads', 'counterparty' => 'Meta', 'occurred_on' => now()->toDateString()]);

        $this->assertSame(1667, $tx->base_amount); // $12.82 × 1.3
        $expense = Expense::sole();
        $this->assertSame([1667, 1282, 'usd', 'card'], [$expense->amount, $expense->original_amount, $expense->original_currency, $expense->paid_via]);

        $this->get('/cashflow?kind=expenses')->assertInertia(fn ($page) => $page->where('entries.0.original.amount', -1282)->where('entries.0.original.currency', 'usd'));
    }
}
