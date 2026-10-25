<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalLockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['password' => Hash::make('secret-pass')]);
        $this->wallet = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'BDT']);
    }

    public function test_team_members_never_see_the_personal_side(): void
    {
        $member = User::factory()->notOwner()->create();
        $this->actingAs($member);

        $this->get('/personal')->assertForbidden();
        $this->get('/personal/loans')->assertForbidden();
        $this->get('/money?scope=personal')->assertForbidden();
        $this->post('/money', ['kind' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 10, 'category' => 'food', 'occurred_on' => now()->toDateString()])->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('auth.user.is_owner', false));
        $this->get('/money?scope=business')->assertOk();
    }

    public function test_pin_locks_and_unlocks_the_personal_side(): void
    {
        $this->actingAs($this->owner);
        $this->get('/personal')->assertOk(); // no PIN yet

        $this->post('/personal/pin', ['pin' => '2580', 'pin_confirmation' => '2580', 'password' => 'secret-pass'])->assertSessionHasErrors('pin'); // 6 digits now
        $this->post('/personal/pin', ['pin' => '258025', 'pin_confirmation' => '258025', 'password' => 'secret-pass'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('258025', $this->owner->fresh()->pin_hash));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->missing('auth.user.pin_hash'));

        $this->post('/personal/lock')->assertRedirect('/personal/unlock'); // the PIN screen, not the business side
        $this->get('/personal/invoices')->assertRedirect('/personal/unlock');
        $this->get('/personal/unlock')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Unlock')->where('length', 6));

        $this->post('/personal/unlock', ['pin' => '111111'])->assertSessionHasErrors('pin');
        $this->post('/personal/unlock', ['pin' => '258025'])->assertRedirect(route('personal.invoices.index'));
        $this->get('/personal')->assertOk();

        // 15 idle minutes later it locks again.
        $this->travel(16)->minutes();
        $this->get('/personal')->assertRedirect('/personal/unlock');
    }

    public function test_too_many_wrong_pins_wait(): void
    {
        $this->owner->forceFill(['pin_hash' => Hash::make('258025')])->save();
        $this->actingAs($this->owner);

        foreach (range(1, 5) as $i) {
            $this->post('/personal/unlock', ['pin' => '000000']);
        }
        $this->post('/personal/unlock', ['pin' => '258025'])->assertSessionHasErrors(['pin' => 'Too many tries — wait 5 min.']);
    }

    public function test_an_old_four_digit_pin_still_opens_until_it_is_changed(): void
    {
        $this->owner->forceFill(['pin_hash' => Hash::make('2580')])->save();
        $this->actingAs($this->owner);

        $this->get('/personal/unlock')->assertInertia(fn ($page) => $page->where('length', 4));
        $this->post('/personal/unlock', ['pin' => '2580'])->assertSessionHasNoErrors();
    }

    public function test_locked_money_calls_get_423_so_offline_entries_are_kept(): void
    {
        $this->owner->forceFill(['pin_hash' => Hash::make('258025')])->save();
        $this->actingAs($this->owner);

        $this->postJson('/money', ['kind' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 10, 'category' => 'food', 'occurred_on' => now()->toDateString()])
            ->assertStatus(423);
        $this->assertSame(0, Ledger::balance($this->wallet));
    }

    public function test_search_hides_personal_results_unless_unlocked(): void
    {
        Ledger::save(['kind' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 5000, 'category' => 'food', 'counterparty' => 'Shwapno', 'occurred_on' => now()->toDateString()]);
        $this->owner->forceFill(['pin_hash' => Hash::make('258025')])->save();
        $this->actingAs($this->owner);

        $this->getJson('/search?q=shwapno')->assertJsonPath('groups', []);
        $this->post('/personal/unlock', ['pin' => '258025']);
        $this->getJson('/search?q=shwapno')->assertJsonPath('groups.0.items.0.title', 'Shwapno');
    }
}
