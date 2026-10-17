<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\MoneyTransaction;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Account $sgd;

    private Account $bdt;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('currency', 'sgd');
        $this->actingAs(User::factory()->create());
        $this->sgd = Account::create(['name' => 'DBS Business', 'scope' => 'business', 'kind' => 'bank', 'currency' => 'SGD']);
        $this->bdt = Account::create(['name' => 'City Bank BDT', 'scope' => 'business', 'kind' => 'bank', 'currency' => 'BDT']);
    }

    public function test_paid_live_payout_lands_minus_the_bank_fee_and_the_fee_is_an_expense(): void
    {
        Setting::put('bank_fee_rate', '0.3');
        Setting::put('payout_account_id', (string) $this->sgd->id);
        $payout = Payout::withoutGlobalScopes()->create(['stripe_payout_id' => 'po_1', 'amount' => 100000, 'currency' => 'sgd', 'status' => 'paid', 'livemode' => true, 'occurred_at' => now()]);

        Ledger::recordPayout($payout);
        Ledger::recordPayout($payout); // only once

        $this->assertSame(99700, Ledger::balance($this->sgd));
        $this->assertSame(1, MoneyTransaction::count());
        $this->assertDatabaseHas('expenses', ['category' => 'bank_fees', 'amount' => 300]);
    }

    public function test_test_mode_payouts_never_touch_real_balances(): void
    {
        Setting::put('payout_account_id', (string) $this->sgd->id);
        $payout = Payout::withoutGlobalScopes()->create(['stripe_payout_id' => 'po_t', 'amount' => 5000, 'currency' => 'sgd', 'status' => 'paid', 'livemode' => false, 'occurred_at' => now()]);

        $this->assertNull(Ledger::recordPayout($payout));
    }

    public function test_convert_then_pay_salary_in_taka_counts_at_the_rate_actually_paid(): void
    {
        $this->post('/money', ['kind' => 'income', 'account_id' => $this->sgd->id, 'amount' => 1000, 'category' => 'stripe_payout', 'occurred_on' => '2026-10-01']);

        // S$900 → ৳81,000 with a S$5 fee.
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->sgd->id, 'to_account_id' => $this->bdt->id, 'amount' => 900, 'to_amount' => 81000, 'fee' => 5, 'occurred_on' => '2026-10-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame(9500, Ledger::balance($this->sgd));        // 1000 − 900 − 5
        $this->assertSame(8100000, Ledger::balance($this->bdt));
        $this->assertEqualsWithDelta(900 / 81000, Ledger::rate($this->bdt), 1e-9);
        $this->assertDatabaseHas('expenses', ['category' => 'bank_fees', 'amount' => 500]);

        // ৳45,000 salary from the taka account = S$500.
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->bdt->id, 'amount' => 45000, 'category' => 'salary', 'counterparty' => 'Rahim', 'occurred_on' => '2026-10-05']);

        $this->assertSame(3600000, Ledger::balance($this->bdt));
        $this->assertDatabaseHas('expenses', ['category' => 'salary', 'amount' => 50000, 'original_amount' => 4500000, 'original_currency' => 'bdt', 'vendor' => 'Rahim']);
        $this->assertSame(50500, (int) Expense::sum('amount'), 'salary S$500 + fee S$5, nothing counted twice');
    }

    public function test_personal_spending_is_tracked_but_never_hits_business_expenses(): void
    {
        $wallet = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'BDT', 'opening_balance' => 1000000, 'opening_rate' => 0.011]);

        $this->post('/money', ['kind' => 'expense', 'account_id' => $wallet->id, 'amount' => 2500, 'category' => 'food', 'counterparty' => 'Shwapno', 'description' => 'Groceries', 'occurred_on' => now()->toDateString()]);

        $this->assertSame(750000, Ledger::balance($wallet));
        $this->assertSame(0, Expense::count());

        $this->get('/money?scope=personal')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Money/Index')
                ->where('summary.spent', 2750)              // ৳2,500 at S$0.011
                ->where('summary.by_category.food', 2750)
                ->where('accounts.2.balance', 750000));
    }

    public function test_moving_business_money_to_personal_is_an_owner_draw_not_an_expense(): void
    {
        $personal = Account::create(['name' => 'My DBS', 'scope' => 'personal', 'kind' => 'bank', 'currency' => 'SGD']);
        $this->post('/money', ['kind' => 'income', 'account_id' => $this->sgd->id, 'amount' => 500, 'occurred_on' => now()->toDateString()]);
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->sgd->id, 'to_account_id' => $personal->id, 'amount' => 200, 'description' => 'Owner draw', 'occurred_on' => now()->toDateString()]);

        $this->assertSame(0, Expense::count());
        $this->get('/money?scope=business')->assertInertia(fn ($page) => $page->where('summary.sent_out', 20000)->where('summary.net', 30000));
        $this->get('/money?scope=personal')->assertInertia(fn ($page) => $page->where('summary.came_in', 20000));
    }

    public function test_the_last_chosen_tab_is_remembered(): void
    {
        $this->get('/money?scope=personal')->assertInertia(fn ($page) => $page->where('scope', 'personal'));
        $this->get('/money')->assertInertia(fn ($page) => $page->where('scope', 'personal'));
        $this->get('/money?scope=business')->assertInertia(fn ($page) => $page->where('scope', 'business'));
    }

    public function test_personal_dashboard_shows_only_personal_money_and_six_months(): void
    {
        $wallet = Account::create(['name' => 'My DBS', 'scope' => 'personal', 'kind' => 'bank', 'currency' => 'SGD', 'opening_balance' => 50000]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $wallet->id, 'amount' => 120, 'category' => 'food', 'occurred_on' => now()->toDateString()]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $wallet->id, 'amount' => 80, 'category' => 'home', 'occurred_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->sgd->id, 'amount' => 999, 'category' => 'software', 'occurred_on' => now()->toDateString()]);

        $this->get('/personal')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Dashboard')
            ->has('accounts', 1)
            ->where('accounts.0.balance', 30000)
            ->where('current.spent', 12000)
            ->where('previous.spent', 8000)
            ->has('months', 6)
            ->has('recent', 2));

        // The Money link then opens on the personal side.
        $this->get('/money')->assertInertia(fn ($page) => $page->where('scope', 'personal'));
        $this->get('/dashboard');
        $this->get('/money')->assertInertia(fn ($page) => $page->where('scope', 'business'));
    }

    public function test_deleting_a_transaction_removes_its_expenses(): void
    {
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->sgd->id, 'amount' => 30, 'fee' => 1, 'category' => 'software', 'occurred_on' => now()->toDateString()]);
        $this->assertSame(2, Expense::count());

        $this->delete('/money/'.MoneyTransaction::first()->id);

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Ledger::balance($this->sgd));
    }

    public function test_a_conversion_needs_the_amount_that_arrived(): void
    {
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->sgd->id, 'to_account_id' => $this->bdt->id, 'amount' => 100, 'occurred_on' => now()->toDateString()])
            ->assertSessionHasErrors('to_amount');
    }
}
