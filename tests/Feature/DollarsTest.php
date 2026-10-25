<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** nSave Main's dollars: from my taka, the business's taka or nSave Copy — each with its rate, and my average. */
class DollarsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_dollar_in_shows_where_it_came_from_and_my_average_rate(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $ucb = Account::create(['name' => 'UCB', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'currency' => 'BDT']);
        $dhaka = Account::create(['name' => 'Dhaka Bank', 'scope' => 'business', 'kind' => 'bank', 'provider' => 'dhaka', 'currency' => 'BDT']);
        $copy = Account::create(['name' => 'nSave Copy', 'scope' => 'business', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD']);
        $main = Account::create(['name' => 'nSave Main', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD']);

        $move = fn ($from, int $paid, int $got, int $fee = 0) => Ledger::save(['kind' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $main->id,
            'amount' => $paid, 'to_amount' => $got, 'fee' => $fee, 'occurred_on' => '2026-10-20']);
        $move($ucb, 1190000, 10000);          // my taka: ৳11,900 → $100 (119)
        $move($dhaka, 1180000, 10000, 20000); // business taka: ৳11,800 + ৳200 fee → $100 (120)
        $move($copy, 5000, 5000);             // nSave Copy → Main: $50

        $this->get('/personal/dollars')->assertInertia(fn ($page) => $page->component('Personal/Dollars')
            ->where('account.name', 'nSave Main')
            ->where('accounts', fn ($list) => collect($list)->firstWhere('name', 'nSave Main')['balance'] === 25000)
            ->where('average', 119.5)
            ->where('totals.my_taka.got', 10000)
            ->where('totals.business_taka.paid', 1200000)
            ->where('totals.business_dollars.got', 5000)
            ->has('rows', 3));
    }
}
