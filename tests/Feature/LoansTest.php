<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Loan;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Reminders;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoansTest extends TestCase
{
    use RefreshDatabase;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'BDT', 'opening_balance' => 5000000]);
    }

    public function test_lending_moves_money_out_but_is_not_spending_and_repayments_settle_it(): void
    {
        $this->post('/personal/loans', [
            'direction' => 'lent', 'person' => 'Rahim', 'currency' => 'BDT', 'principal' => 10000, 'account_id' => $this->bkash->id,
            'started_on' => now()->toDateString(), 'due_on' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $loan = Loan::sole();
        $this->assertSame(4000000, Ledger::balance($this->bkash), 'money left bKash');
        $this->get('/personal')->assertInertia(fn ($page) => $page
            ->where('stats.BDT.current.spent', 0)
            ->where('loans.totals.BDT.to_receive', 1000000));

        $this->post("/personal/loans/{$loan->id}/payments", ['amount' => 4000, 'paid_on' => now()->toDateString(), 'account_id' => $this->bkash->id])->assertSessionHasNoErrors();
        $this->assertSame(600000, $loan->fresh()->outstanding());
        $this->assertSame(4400000, Ledger::balance($this->bkash));
        $this->get('/personal')->assertInertia(fn ($page) => $page->where('stats.BDT.current.in', 0)->where('loans.totals.BDT.to_receive', 600000));

        $this->post("/personal/loans/{$loan->id}/payments", ['amount' => 6000, 'paid_on' => now()->toDateString()])->assertSessionHas('success', 'Settled in full. 🎉');
        $this->assertSame(0, $loan->fresh()->outstanding());
    }

    public function test_borrowing_brings_money_in_and_deleting_undoes_the_entries(): void
    {
        $this->post('/personal/loans', ['direction' => 'borrowed', 'person' => 'Karim', 'currency' => 'BDT', 'principal' => 20000,
            'account_id' => $this->bkash->id, 'started_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(7000000, Ledger::balance($this->bkash));

        $loan = Loan::sole();
        $this->post("/personal/loans/{$loan->id}/payments", ['amount' => 5000, 'paid_on' => now()->toDateString(), 'account_id' => $this->bkash->id]);
        $this->assertSame(6500000, Ledger::balance($this->bkash));
        $this->get('/personal/loans')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Loans')->where('totals.BDT.to_pay', 1500000));

        $this->delete("/personal/loans/{$loan->id}");
        $this->assertSame(0, MoneyTransaction::count());
        $this->assertSame(5000000, Ledger::balance($this->bkash));
    }

    public function test_account_must_match_the_loan_currency(): void
    {
        $this->post('/personal/loans', ['direction' => 'lent', 'person' => 'Rahim', 'currency' => 'SGD', 'principal' => 100,
            'account_id' => $this->bkash->id, 'started_on' => now()->toDateString()])->assertSessionHasErrors('account_id');
    }

    public function test_morning_reminder_mentions_loans_due(): void
    {
        Loan::create(['direction' => 'lent', 'person' => 'Rahim', 'currency' => 'BDT', 'principal' => 500000, 'started_on' => '2026-10-01', 'due_on' => '2026-10-16']);
        Loan::create(['direction' => 'borrowed', 'person' => 'Karim', 'currency' => 'BDT', 'principal' => 200000, 'started_on' => '2026-10-01', 'due_on' => '2026-10-15']);

        $m = app(Reminders::class)->morning(CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Dhaka'));

        $this->assertStringContainsString('🤝 Rahim owes you ৳5,000 (due 16 Oct)', $m['body']);
        $this->assertStringContainsString('🤝 Pay Karim ৳2,000 (due today)', $m['body']);
    }
}
