<?php

namespace Tests\Feature;

use App\Http\Controllers\GuideController;
use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Envelope;
use App\Models\EnvelopeMonth;
use App\Models\Loan;
use App\Models\MoneyTransaction;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\PersonalInvoicing;
use App\Services\PersonalReminders;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $this->bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'last4' => '1122', 'currency' => 'BDT', 'opening_balance' => 5000000]);
    }

    public function test_bank_sms_lands_in_the_account_with_those_last_4_digits_and_tells_me(): void
    {
        $salary = Account::create(['name' => 'UCB salary', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '4567', 'currency' => 'BDT']);
        Account::create(['name' => 'UCB savings', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '9901', 'currency' => 'BDT', 'purpose' => 'savings']);

        $this->postJson('/inbox/sms/'.AutoEntries::token(), ['from' => 'UCB', 'text' => 'Your A/C No. 123XXXX4567 is credited with BDT 20,000.00 on 25-10-2026. Current Balance BDT 45,500.00'])
            ->assertOk()->assertJson(['status' => 'added']);

        $this->assertSame(2000000, Ledger::balance($salary));
        $this->assertSame(4550000, $salary->fresh()->bank_balance, 'what the bank says, to compare');
        $note = AppNotification::sole();
        $this->assertSame(['credit', '💰 UCB salary ••4567 +৳20,000'], [$note->kind, $note->title]);
        $this->assertStringContainsString('Balance ৳20,000', $note->body);
    }

    public function test_money_moved_between_my_own_accounts_becomes_one_transfer(): void
    {
        $from = Account::create(['name' => 'UCB', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '1603', 'currency' => 'BDT', 'opening_balance' => 1112200]);
        $to = Account::create(['name' => 'UCB salary', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '6318', 'currency' => 'BDT', 'opening_balance' => 8921000]);
        $entries = app(AutoEntries::class);

        $entries->ingest('sms', 'UCB', 'Your A/C (***1603) has been debited BDT 11,122.00 for Ibanking Fund Transfer - Debit. Avl Bal: BDT 0.00 @ 02:23 PM');
        $entries->ingest('sms', 'UCB', 'Your A/C (***6318) has been credited BDT 11,122.00 for I BANKING FUND TRANSFER - CREDIT. Avl Bal: BDT 1,00,332.00 @ 02:23 PM');

        $tx = MoneyTransaction::sole();
        $this->assertSame(['transfer', $from->id, $to->id, 1112200], [$tx->kind, $tx->account_id, $tx->to_account_id, $tx->amount]);
        $this->assertSame([0, 10033200], [Ledger::balance($from), Ledger::balance($to)]);
        $this->assertSame(10033200, $to->fresh()->bank_balance, 'matches what the bank says');
        $this->get('/personal')->assertInertia(fn ($page) => $page->where('stats.BDT.current.spent', 0)->where('stats.BDT.current.in', 0));
        $this->assertStringContainsString('UCB ••1603 → UCB salary ••6318', AppNotification::latest('id')->first()->title);
    }

    public function test_wallet_app_notifications_from_the_phone_app_are_entries_too(): void
    {
        $nsave = Account::create(['name' => 'nSave', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD']);

        $this->postJson('/inbox/sms/'.AutoEntries::token(), ['source' => 'notification', 'app' => 'nSave', 'text' => 'You received $250.00 from Upwork. Your new balance is $1,240.10'])
            ->assertOk()->assertJson(['status' => 'added']);

        $this->assertSame(25000, Ledger::balance($nsave));
        $this->assertDatabaseHas('inbox_messages', ['source' => 'app', 'status' => 'added']);
    }

    public function test_the_envelope_hand_out_is_one_debit_and_spends_only_reduce_the_envelope(): void
    {
        $this->post('/personal/envelopes', ['name' => 'Songsar', 'emoji' => '🏠', 'currency' => 'BDT', 'amount' => 15000, 'account_id' => $this->bkash->id,
            'purposes' => ['Bazar', 'Electricity'], 'day' => 1, 'active' => true])->assertSessionHasNoErrors();
        $envelope = Envelope::sole();

        // I send the money with bKash: its SMS is the hand-out.
        app(AutoEntries::class)->ingest('sms', 'bKash', 'Send Money Tk 15,000.00 to 01811000000 successful. Fee Tk 0.00. Balance Tk 35,000.00. TrxID 7ABCD123 at 25/10/2026 10:00');
        $month = EnvelopeMonth::sole();
        $this->assertSame([1500000, 'envelope'], [$month->assigned, MoneyTransaction::find($month->money_transaction_id)->category]);
        $this->assertSame(3500000, Ledger::balance($this->bkash));

        $this->post("/personal/envelopes/{$envelope->id}/spends", ['purpose' => 'Bazar', 'amount' => 3200, 'spent_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->post("/personal/envelopes/{$envelope->id}/spends", ['purpose' => 'Medicine', 'amount' => 800, 'spent_on' => now()->toDateString()]);

        $this->assertSame(3500000, Ledger::balance($this->bkash), 'spends never touch bKash again');
        $this->get('/personal/plan')->assertInertia(fn ($page) => $page
            ->where('envelopes.0.assigned', 1500000)
            ->where('envelopes.0.spent', 400000)
            ->where('envelopes.0.left', 1100000)
            ->where('envelopes.0.by_purpose.Bazar', 320000)
            ->where('envelopes.0.purposes', ['Bazar', 'Electricity', 'Medicine']));

        // A second SMS of the same amount is ordinary spending, not a second hand-out.
        app(AutoEntries::class)->ingest('sms', 'bKash', 'Send Money Tk 15,000.00 to 01911000000 successful. Fee Tk 0.00. TrxID 7ABCD999 at 26/10/2026 10:00');
        $this->assertSame(1, EnvelopeMonth::count());

        $this->delete("/personal/envelope-months/{$month->id}");
        $this->assertSame(3500000, Ledger::balance($this->bkash), 'undo puts the hand-out back');
    }

    public function test_handing_out_by_hand_debits_the_source_once(): void
    {
        $envelope = Envelope::create(['name' => 'Mobile', 'currency' => 'BDT', 'amount' => 100000, 'account_id' => $this->bkash->id, 'day' => 1]);

        $this->post("/personal/envelopes/{$envelope->id}/assign", ['month' => now()->format('Y-m'), 'amount' => 1000, 'account_id' => $this->bkash->id, 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->post("/personal/envelopes/{$envelope->id}/assign", ['month' => now()->format('Y-m'), 'amount' => 1000, 'date' => now()->toDateString()])->assertStatus(422);

        $this->assertSame(4900000, Ledger::balance($this->bkash));
    }

    public function test_money_i_owe_reminds_two_days_before_one_day_before_that_morning_and_one_hour_before_the_day_ends(): void
    {
        $this->post('/personal/loans', ['direction' => 'borrowed', 'person' => 'Karim', 'currency' => 'BDT', 'principal' => 5000,
            'started_on' => '2026-10-20', 'due_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->post('/personal/loans', ['direction' => 'lent', 'person' => 'No-date Nabil', 'currency' => 'BDT', 'principal' => 2000, 'started_on' => '2026-10-20']);
        $run = fn (string $at) => app(PersonalReminders::class)->run(CarbonImmutable::parse($at, 'Asia/Dhaka'));

        $this->assertSame(0, $run('2026-11-08 08:30'), 'not before 9am');
        $this->assertSame(1, $run('2026-11-08 09:05'));
        $this->assertSame(0, $run('2026-11-08 18:00'), 'once');
        $this->assertSame(1, $run('2026-11-09 09:00'));
        $this->assertSame(1, $run('2026-11-10 09:01'));
        $this->assertSame(1, $run('2026-11-10 23:00'));
        $this->assertSame(0, $run('2026-11-10 23:30'));

        $titles = AppNotification::pluck('title')->all();
        $this->assertSame(['🤝 Pay Karim ৳5,000 in 2 days', '🤝 Pay Karim ৳5,000 tomorrow', '📌 Today: pay Karim ৳5,000', '⏰ 1 hour left: pay Karim ৳5,000'], $titles);
    }

    public function test_money_owed_to_me_reminds_from_two_days_before_only_when_it_has_a_date(): void
    {
        Loan::create(['direction' => 'lent', 'person' => 'Rahim', 'phone' => '01711000000', 'currency' => 'BDT', 'principal' => 300000, 'started_on' => '2026-10-01', 'due_on' => '2026-11-05']);
        $run = fn (string $at) => app(PersonalReminders::class)->run(CarbonImmutable::parse($at, 'Asia/Dhaka'));

        $this->assertSame(0, $run('2026-11-02 10:00'));
        $this->assertSame(1, $run('2026-11-03 10:00'));
        $this->assertSame(1, $run('2026-11-04 10:00'));
        $this->assertSame(1, $run('2026-11-05 10:00'));
        $this->assertStringContainsString('01711000000', AppNotification::latest('id')->first()->body);
    }

    public function test_lending_after_the_bkash_sms_came_in_reuses_that_entry_and_keeps_the_fee(): void
    {
        app(AutoEntries::class)->ingest('sms', 'bKash', 'Send Money Tk 10,000.00 to 01811000000 successful. Fee Tk 18.50. TrxID 5LOAN123 at 25/10/2026 10:00');

        $this->post('/personal/loans', ['direction' => 'lent', 'person' => 'Rahim', 'currency' => 'BDT', 'principal' => 10000, 'fee' => 18.5,
            'account_id' => $this->bkash->id, 'started_on' => now()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame(1, MoneyTransaction::count(), 'no second debit');
        $this->assertSame(['loan_given', 'Rahim', 1850], [MoneyTransaction::sole()->category, MoneyTransaction::sole()->counterparty, MoneyTransaction::sole()->fee]);
        $this->assertSame(5000000 - 1000000 - 1850, Ledger::balance($this->bkash));
        $this->get('/personal')->assertInertia(fn ($page) => $page->where('stats.BDT.current.spent', 1850)->where('stats.BDT.current.by_category.bank_fees', 1850));
    }

    public function test_a_credit_already_in_my_books_can_be_assigned_to_a_job_invoice(): void
    {
        $client = PersonalClient::create(['name' => 'EDGE Education Bangladesh']);
        $invoice = app(PersonalInvoicing::class)->create($client, [['description' => 'Monthly work', 'qty' => 1, 'rate' => 500000]], CarbonImmutable::today(), CarbonImmutable::today()->addDays(2));
        $tx = Ledger::save(['kind' => 'income', 'account_id' => $this->bkash->id, 'amount' => 500000, 'category' => 'other_income', 'description' => 'Auto · Bkash sms', 'occurred_on' => now()->toDateString()]);

        $this->post("/personal/invoices/{$invoice->id}/link", ['money_transaction_id' => $tx->id])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(['work', 5500000], [$tx->fresh()->category, Ledger::balance($this->bkash)]);
        $this->post("/personal/invoices/{$invoice->id}/link", ['money_transaction_id' => $tx->id])->assertStatus(422);

        // Removing the payment keeps the money that really came in.
        $this->delete('/personal/invoice-payments/'.$invoice->payments()->sole()->id);
        $this->assertSame(['unpaid', 5500000], [$invoice->fresh()->status, Ledger::balance($this->bkash)]);
        $this->assertNotNull(PersonalInvoice::find($invoice->id));
    }

    public function test_the_family_guide_opens_only_by_its_secret_link_and_shows_only_last_4_digits(): void
    {
        Account::create(['name' => 'Rizon', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'rizon', 'currency' => 'USD', 'last4' => '7788', 'opening_balance' => 50000]);

        $this->get('/g/not-the-link')->assertNotFound();
        auth()->logout();
        $this->get('/g/'.GuideController::token())->assertOk()->assertInertia(fn ($page) => $page->component('Public/Guide')
            ->has('accounts', 2)
            ->where('accounts.0.last4', '1122')
            ->where('accounts.1.currency', 'USD')
            ->where('accounts.1.guide', fn ($text) => str_contains($text, 'Rizon app')));
    }

    public function test_a_test_notification_goes_to_the_feed_from_the_app_or_the_panel(): void
    {
        $this->postJson('/app/test/'.AutoEntries::token())->assertOk();
        $this->postJson('/personal/app/test')->assertOk();
        $this->postJson('/app/test/wrong')->assertNotFound();

        $this->assertSame(2, AppNotification::where('title', '🔔 MH-Bot test')->count());
    }

    public function test_the_bell_shows_notifications_and_marks_them_read(): void
    {
        AppNotification::create(['kind' => 'credit', 'title' => '💰 bKash +৳500']);
        AppNotification::create(['kind' => 'reminder', 'title' => '🤝 Pay Karim']);

        $this->getJson('/personal/feed')->assertOk()->assertJsonPath('unread', 2)->assertJsonPath('items.0.title', '🤝 Pay Karim');
        $this->postJson('/personal/feed/read')->assertOk();
        $this->getJson('/personal/feed')->assertJsonPath('unread', 0);
    }

    public function test_senders_and_ignore_words_are_one_list_for_the_phone_and_the_panel(): void
    {
        $url = '/app/config/'.AutoEntries::token();
        $this->getJson($url)->assertOk()->assertJsonPath('whitelist', true)->assertJsonFragment(['name' => 'UCB', 'type' => 'sms', 'on' => true]);

        // The phone switches UCB off and adds a word; the panel sees it.
        $this->putJson($url, ['senders' => [['name' => 'UCB', 'type' => 'sms', 'on' => false], ['name' => 'nSave', 'type' => 'app', 'on' => true]], 'ignore' => ['otp', 'Offer']])->assertOk();
        $this->get('/personal/inbox')->assertInertia(fn ($page) => $page->where('rules.senders.0.on', false)->where('rules.ignore', ['otp', 'offer']));

        // The panel saves; the phone gets it.
        $this->put('/personal/inbox-rules', ['senders' => [['name' => 'Cellfin', 'type' => 'sms', 'on' => true]], 'ignore' => ['otp'], 'whitelist' => false])->assertSessionHasNoErrors();
        $this->getJson($url)->assertJsonPath('senders.0.name', 'Cellfin')->assertJsonPath('whitelist', false);
        $this->getJson('/app/config/wrong')->assertNotFound();

        // An ignore word keeps a message out, unless it has a transaction ID.
        $this->assertSame('skipped', app(AutoEntries::class)->ingest('sms', 'bKash', 'Your OTP for payment of Tk 500.00 is 4421')->status);
    }

    public function test_the_phone_app_pairs_from_the_unlocked_side_and_reads_new_notifications(): void
    {
        AppNotification::create(['kind' => 'info', 'title' => 'Old one']);
        $pair = $this->postJson('/personal/app/pair')->assertOk()->json();
        $this->assertSame(AutoEntries::token(), $pair['token']);

        app(AutoEntries::class)->ingest('sms', 'bKash', 'You have received Tk 1,500.00 from 01711000000. Fee Tk 0.00. Balance Tk 51,500.00. TrxID 9ZZZ1111 at 25/10/2026 10:15');

        $this->getJson('/app/feed/'.$pair['token'].'?after='.$pair['last_id'])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.kind', 'credit');
        $this->getJson('/app/feed/wrong')->assertNotFound();

        $this->actingAs(User::factory()->create(['is_owner' => false]));
        $this->postJson('/personal/app/pair')->assertForbidden();
    }
}
