<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\MoneyTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Account $dbs;

    private Account $savings;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('currency', 'sgd');
        $this->actingAs(User::factory()->create());
        $this->dbs = Account::create(['name' => 'DBS', 'scope' => 'personal', 'kind' => 'bank', 'currency' => 'SGD', 'opening_balance' => 300000]);
        $this->savings = Account::create(['name' => 'Aspire Savings', 'scope' => 'personal', 'kind' => 'bank', 'purpose' => 'savings', 'currency' => 'SGD']);
    }

    public function test_saved_and_spent_are_separate_and_left_is_what_remains(): void
    {
        $today = now()->toDateString();
        $this->post('/money', ['kind' => 'income', 'account_id' => $this->dbs->id, 'amount' => 4000, 'category' => 'salary', 'occurred_on' => $today]);
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 1500, 'category' => 'home', 'occurred_on' => $today]);
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->dbs->id, 'to_account_id' => $this->savings->id, 'amount' => 1000, 'occurred_on' => $today]);
        $this->post('/money', ['kind' => 'transfer', 'account_id' => $this->savings->id, 'to_account_id' => $this->dbs->id, 'amount' => 200, 'occurred_on' => $today]);

        $this->get('/personal')->assertInertia(fn ($page) => $page
            ->where('stats.SGD.current.in', 400000)
            ->where('stats.SGD.current.spent', 150000)
            ->where('stats.SGD.current.saved', 80000)            // 1000 put aside − 200 taken back
            ->where('stats.SGD.current.left', 170000)
            ->where('stats.SGD.savings', 80000)
            ->where('stats.SGD.spending', 300000 + 400000 - 150000 - 80000)
            ->where('stats.SGD.total', 300000 + 400000 - 150000));
    }

    public function test_budgets_show_spending_against_the_limit(): void
    {
        $this->post('/personal/budgets', ['currency' => 'SGD', 'limits' => ['food' => '300', 'home' => '', 'nonsense' => '5']])->assertSessionHasNoErrors();
        $this->assertSame(1, Budget::count());

        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 345.50, 'category' => 'food', 'occurred_on' => now()->toDateString()]);

        $this->get('/personal')->assertInertia(fn ($page) => $page
            ->where('stats.SGD.budgets.0.category', 'food')
            ->where('stats.SGD.budgets.0.limit', 30000)
            ->where('stats.SGD.budgets.0.spent', 34550)
            ->has('stats.BDT.budgets', 0));

        // 0 / empty clears it.
        $this->post('/personal/budgets', ['currency' => 'SGD', 'limits' => ['food' => '0']]);
        $this->assertSame(0, Budget::count());
    }

    public function test_a_goal_tracks_its_savings_account(): void
    {
        $this->post('/personal/goals', ['name' => 'Emergency fund', 'account_id' => $this->dbs->id, 'target' => 10000, 'due_on' => now()->addYear()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame('savings', $this->dbs->fresh()->purpose, 'a goal account becomes a savings account');
        $this->get('/personal')->assertInertia(fn ($page) => $page
            ->where('goals.0.name', 'Emergency fund')
            ->where('goals.0.saved', 300000)
            ->where('goals.0.target', 1000000));
    }

    public function test_marking_a_bill_paid_records_it_once_a_month(): void
    {
        $bill = Bill::create(['name' => 'Room rent', 'account_id' => $this->dbs->id, 'category' => 'home', 'amount' => 120000, 'day' => 1, 'counterparty' => 'Landlord']);

        $this->get('/personal')->assertInertia(fn ($page) => $page->where('bills.0.paid_on', null));

        $this->post("/personal/bills/{$bill->id}/pay")->assertSessionHasNoErrors();
        $this->post("/personal/bills/{$bill->id}/pay")->assertSessionHasErrors('bill');

        $tx = MoneyTransaction::sole();
        $this->assertSame(['expense', 120000, 'home', 'Landlord', $bill->id], [$tx->kind, $tx->amount, $tx->category, $tx->counterparty, $tx->bill_id]);
        $this->get('/personal')->assertInertia(fn ($page) => $page->where('bills.0.paid_on', now()->toDateString())->where('bills.0.overdue', false));
    }

    public function test_entries_saved_offline_are_accepted_as_json(): void
    {
        $this->postJson('/money', ['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 12.5, 'category' => 'food', 'occurred_on' => now()->toDateString()])
            ->assertCreated()->assertJsonStructure(['id']);

        $this->postJson('/money', ['kind' => 'expense', 'account_id' => $this->dbs->id, 'occurred_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_month_can_be_downloaded_as_csv(): void
    {
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 8.6, 'category' => 'food', 'counterparty' => 'FairPrice', 'occurred_on' => now()->toDateString()]);

        $csv = $this->get('/money/export?scope=personal&month='.now()->format('Y-m'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Date,Type,"From / account"', $csv);
        $this->assertStringContainsString('Spent,DBS,,"Food & groceries",FairPrice,,8.60,SGD', $csv);
    }

    public function test_the_app_can_be_installed_and_used_offline(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }

        // The personal side installs as its own app.
        $personal = json_decode(file_get_contents(public_path('personal.webmanifest')), true);
        $this->assertSame(['MH-Pilot', './personal'], [$personal['name'], $personal['start_url']]);
        foreach ($personal['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
        $this->get('/personal')->assertSee('personal.webmanifest', false);
        $this->get('/dashboard')->assertSee('/manifest.webmanifest', false);
    }
}
