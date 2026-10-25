<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\User;
use App\Services\PersonalReminders;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentsTest extends TestCase
{
    use RefreshDatabase;

    private Account $ucb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        $this->ucb = Account::create(['name' => 'UCB', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'currency' => 'BDT', 'opening_balance' => 200000000]);
    }

    private function land(): Account
    {
        return Account::firstWhere('kind', 'investment');
    }

    public function test_buying_land_moves_money_into_it_without_counting_as_spending(): void
    {
        $this->post('/personal/investments', ['name' => 'Land at Gopai', 'scope' => 'personal', 'inv_type' => 'land', 'currency' => 'BDT',
            'from_account_id' => $this->ucb->id, 'amount' => 1000000, 'fee' => 5000, 'date' => now()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame(200000000 - 100000000 - 500000, Ledger::balance($this->ucb));
        $this->assertSame(100000000, Ledger::balance($this->land()));
        $this->get('/personal')->assertInertia(fn ($page) => $page
            ->where('stats.BDT.current.spent', 500000)            // only the registration fee
            ->where('stats.BDT.total', 200000000 - 100000000 - 500000)
            ->where('stats.BDT.investments', 100000000)
            ->where('stats.BDT.net_worth', 200000000 - 500000));
    }

    public function test_value_returns_and_selling_show_what_it_made(): void
    {
        $this->post('/personal/investments', ['name' => 'Land at Gopai', 'scope' => 'personal', 'inv_type' => 'land', 'currency' => 'BDT',
            'from_account_id' => $this->ucb->id, 'amount' => 1000000, 'date' => now()->toDateString()]);
        $land = $this->land();

        $this->put("/personal/investments/{$land->id}/value", ['value' => 1500000])->assertSessionHasNoErrors();
        $this->post("/personal/investments/{$land->id}/return", ['account_id' => $this->ucb->id, 'amount' => 20000, 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->get('/personal/investments')->assertInertia(fn ($page) => $page->component('Personal/Investments')
            ->where('investments.0.invested', 100000000)->where('investments.0.current', 150000000)
            ->where('investments.0.returns', 2000000)->where('investments.0.gain', 52000000));

        // Sold for 15 lakh: closed, the profit stays on record.
        $this->post("/personal/investments/{$land->id}/move", ['direction' => 'out', 'account_id' => $this->ucb->id, 'amount' => 1500000, 'date' => now()->toDateString(), 'close' => true])->assertSessionHasNoErrors();
        $this->assertTrue($land->fresh()->archived);
        $this->get('/personal/investments')->assertInertia(fn ($page) => $page->where('investments.0.gain', 52000000));
        $this->assertSame(200000000 + 50000000 + 2000000, Ledger::balance($this->ucb));
    }

    public function test_an_fdr_reminds_a_week_before_and_on_the_day_it_matures(): void
    {
        $this->post('/personal/investments', ['name' => 'UCB FDR', 'scope' => 'personal', 'inv_type' => 'fdr', 'currency' => 'BDT',
            'from_account_id' => $this->ucb->id, 'amount' => 500000, 'date' => '2026-10-01', 'expected_rate' => 9.5, 'maturity_on' => '2026-11-10']);
        $run = fn (string $at) => app(PersonalReminders::class)->run(CarbonImmutable::parse($at, 'Asia/Dhaka'));

        $this->assertSame(1, $run('2026-11-03 09:30'));
        $this->assertSame(0, $run('2026-11-03 18:00'));
        $this->assertSame(1, $run('2026-11-10 09:05'));
        $this->assertSame('📈 UCB FDR matures today', AppNotification::latest('id')->first()->title);
    }

    public function test_our_agro_is_a_business_investment(): void
    {
        $business = Account::create(['name' => 'UCB', 'scope' => 'business', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '1603', 'currency' => 'BDT', 'opening_balance' => 10000000]);

        $this->post('/personal/investments', ['name' => 'Matir Khamar (Agro)', 'scope' => 'business', 'inv_type' => 'business', 'currency' => 'BDT',
            'from_account_id' => $business->id, 'amount' => 50000, 'date' => now()->toDateString()])->assertSessionHasNoErrors();

        $agro = Account::firstWhere('name', 'Matir Khamar (Agro)');
        $this->assertSame(['business', 'investment', 5000000], [$agro->scope, $agro->kind, Ledger::balance($agro)]);
        $this->getJson('/personal/quick')->assertJsonPath('investments.0.name', 'Matir Khamar (Agro)')->assertJsonPath('investments.0.business', true);
    }
}
