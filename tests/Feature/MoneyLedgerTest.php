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

        $this->get('/money?scope=personal')->assertRedirect(route('personal.activity'));
        $this->get('/personal/activity')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personal/Activity')
                ->where('totals.BDT.spent', 250000)          // in taka, never converted
                ->where('totals.BDT.by_category.food', 250000)
                ->where('entries.0.counterparty', 'Shwapno')
                ->where('accounts.0.balance', 750000));
    }

    public function test_moving_business_money_to_personal_is_an_owner_draw_not_an_expense(): void
    {
        $personal = Account::create(['name' => 'My DBS', 'scope' => 'personal', 'kind' => 'bank', 'currency' => 'SGD']);
        $this->post('/money', ['kind' => 'income', 'account_id' => $this->sgd->id, 'amount' => 500, 'occurred_on' => now()->toDateString()]);
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->sgd->id, 'to_account_id' => $personal->id, 'amount' => 200, 'description' => 'Owner draw', 'occurred_on' => now()->toDateString()]);

        $this->assertSame(0, Expense::count());
        $this->get('/money?scope=business')->assertInertia(fn ($page) => $page->where('summary.sent_out', 20000)->where('summary.net', 30000));
        $this->get('/personal/activity')->assertInertia(fn ($page) => $page->where('totals.SGD.in', 20000));
    }

    public function test_the_money_page_is_business_only_and_personal_goes_to_activity(): void
    {
        $this->get('/money?scope=personal&month=2026-09&q=rent')->assertRedirect(route('personal.activity', ['month' => '2026-09', 'q' => 'rent']));
        $this->get('/money')->assertInertia(fn ($page) => $page->component('Money/Index')->where('scope', 'business'));
    }

    public function test_personal_dashboard_keeps_sgd_and_bdt_apart_and_shows_where_spending_came_from(): void
    {
        $dbs = Account::create(['name' => 'DBS Savings', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'dbs', 'currency' => 'SGD', 'opening_balance' => 50000]);
        $bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'currency' => 'BDT', 'opening_balance' => 1000000]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $dbs->id, 'amount' => 120, 'category' => 'food', 'occurred_on' => now()->toDateString()]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $dbs->id, 'amount' => 80, 'category' => 'home', 'occurred_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $bkash->id, 'amount' => 2500, 'fee' => 20, 'category' => 'family', 'occurred_on' => now()->toDateString()]);
        // My own SGD → BDT conversion is neither spending nor income.
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $dbs->id, 'to_account_id' => $bkash->id, 'amount' => 100, 'to_amount' => 9000, 'occurred_on' => now()->toDateString()]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->sgd->id, 'amount' => 999, 'category' => 'software', 'occurred_on' => now()->toDateString()]);

        $this->get('/personal')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Dashboard')
            ->where('currencies', ['SGD', 'BDT'])
            ->has('accounts', 2)
            ->where('accounts.0.name', 'DBS Savings')
            ->where('accounts.0.balance', 50000 - 12000 - 8000 - 10000)
            ->where('accounts.0.month_out', 12000)
            ->where('accounts.1.balance', 1000000 - 252000 + 900000)
            ->where('accounts.1.month_out', 252000)
            ->where('stats.SGD.current.spent', 12000)
            ->where('stats.SGD.previous.spent', 8000)
            ->where('stats.BDT.current.spent', 252000)
            ->where('stats.BDT.current.by_category.family', 250000)
            ->where('stats.BDT.current.by_category.bank_fees', 2000)
            ->where('stats.BDT.current.in', 0)
            ->has('stats.SGD.months', 6)
            ->where('recent.0.account.name', fn ($name) => in_array($name, ['DBS Savings', 'bKash'], true)));

        // Plan shows the same numbers; Activity lists only the chosen month.
        $this->get('/personal/plan')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Plan')->where('stats.SGD.current.spent', 12000));
        $this->get('/personal/activity')->assertInertia(fn ($page) => $page->has('entries', 3)->where('totals.SGD.spent', 12000));
        $this->get('/personal/activity?month='.now()->subMonthNoOverflow()->format('Y-m'))
            ->assertInertia(fn ($page) => $page->has('entries', 1)->where('entries.0.category', 'home')->where('totals.SGD.spent', 8000));
    }

    public function test_quick_add_offers_my_accounts_categories_and_past_payees(): void
    {
        $bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'currency' => 'bdt', 'opening_balance' => 100000]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $bkash->id, 'amount' => 300, 'category' => 'transport', 'counterparty' => 'Pathao', 'occurred_on' => now()->toDateString()]);

        $quick = $this->getJson('/personal/quick')->assertOk()
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.currency', 'BDT')
            ->assertJsonPath('accounts.0.balance', 70000)
            ->assertJsonPath('payees.0.name', 'Pathao')
            ->assertJsonPath('last_account_id', $bkash->id);
        $this->assertNotContains('bank_fees', array_column($quick->json('categories.expense'), 'value'));
    }

    public function test_personal_accounts_are_sgd_or_bdt_and_keep_their_bank(): void
    {
        $this->post('/money/accounts', ['name' => 'Revolut USD', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'USD'])
            ->assertSessionHasErrors(['currency' => 'Personal accounts are SGD or BDT.']);

        $this->post('/money/accounts', ['name' => 'Airwallex', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'airwallex', 'currency' => 'SGD'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('accounts', ['name' => 'Airwallex', 'provider' => 'airwallex', 'currency' => 'SGD']);

        // Business accounts can still be any currency.
        $this->post('/money/accounts', ['name' => 'Wise USD', 'scope' => 'business', 'kind' => 'wallet', 'currency' => 'USD'])->assertSessionHasNoErrors();
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
