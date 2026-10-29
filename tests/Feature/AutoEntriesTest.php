<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\InboxMessage;
use App\Models\MoneyTransaction;
use App\Models\PersonalClient;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\PersonalInvoicing;
use App\Support\InboxRules;
use App\Support\Ledger;
use App\Support\TransactionParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoEntriesTest extends TestCase
{
    use RefreshDatabase;

    public static function messages(): array
    {
        return [
            'bkash received' => ['bKash', 'You have received Tk 1,500.00 from 01711000000. Ref Rent. Fee Tk 0.00. Balance Tk 8,250.50. TrxID 9JK4XY2Z at 24/10/2026 10:15',
                ['provider' => 'bkash', 'direction' => 'in', 'amount' => 150000, 'currency' => 'BDT', 'reference' => '9JK4XY2Z', 'balance' => 825050]],
            'bkash send money' => ['bKash', 'Send Money Tk 500.00 to 01811000000 successful. Ref Ammu. Fee Tk 5.00. Balance Tk 7,745.50. TrxID 8HG3WX1Y at 24/10/2026 12:01',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 50000, 'fee' => 500, 'reference' => '8HG3WX1Y']],
            'nagad received' => ['NAGAD', 'Money Received. Amount: Tk 2000.00 Sender: 01911000000 Ref: N/A TxnID: 73AB12CD Balance: Tk 5000.00 24/10/2026 09:10',
                ['provider' => 'nagad', 'direction' => 'in', 'amount' => 200000, 'reference' => '73AB12CD']],
            'rocket' => ['16216', 'Tk1,000.00 received from A/C:017110000001 Fee:Tk0, Your A/C Balance: Tk3,500.00 TxnId:1234567890 Date:24-OCT-26 10:01:02 am',
                ['provider' => 'rocket', 'direction' => 'in', 'amount' => 100000, 'reference' => '1234567890']],
            'city bank credit' => ['CityBank', 'Dear Customer, your A/C ...4321 has been credited by BDT 25,000.00 on 24-Oct-2026 (NPSB). Avl Bal: BDT 60,210.00',
                ['provider' => 'city', 'direction' => 'in', 'amount' => 2500000, 'last4' => '4321', 'balance' => 6021000]],
            'ucb masked account' => ['UCB', 'Your A/C No. 123XXXX4567 is credited with BDT 20,000.00 on 25-10-2026. Current Balance BDT 45,500.00',
                ['provider' => 'ucb', 'direction' => 'in', 'amount' => 2000000, 'last4' => '4567', 'balance' => 4550000]],
            'ucb bracketed account' => ['UCB', 'Your A/C (***1603) has been debited BDT 11,122.00 for Ibanking Fund Transfer - Debit. Avl Bal: BDT 0.00 @ 02:23 PM',
                ['provider' => 'ucb', 'direction' => 'out', 'amount' => 1112200, 'last4' => '1603', 'balance' => 0]],
            'ucb lakh balance' => ['UCB', 'Your A/C (***6318) has been credited BDT 11,122.00 for I BANKING FUND TRANSFER - CREDIT. Avl Bal: BDT 1,00,332.00 @ 02:23 PM',
                ['provider' => 'ucb', 'direction' => 'in', 'amount' => 1112200, 'last4' => '6318', 'balance' => 10033200]],
            'nsave sent' => ['nsave', 'Money sent. ⬆️ Sent $327.00 to MD ANOWAR HOSSAIN',
                ['provider' => 'nsave', 'direction' => 'out', 'amount' => 32700, 'currency' => 'USD', 'counterparty' => 'MD ANOWAR HOSSAIN']],
            'nsave received' => ['nsave', 'Money received. 💰 Received $327.00 from Md Mujaid Hossain',
                ['provider' => 'nsave', 'direction' => 'in', 'amount' => 32700, 'currency' => 'USD']],
            // bKash / Nagad / Rocket / Upay apps' own notifications (title. text)
            'bkash app recharge' => ['bKash', 'Mobile Recharge. Mobile Recharge of Tk 50.00 to 01711000000 is successful. Balance Tk 2,106.82. TrxID CJQ3XK2L1A',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 5000, 'reference' => 'CJQ3XK2L1A']],
            'bkash app add money' => ['bKash', 'Add Money. Add Money Tk 1,000.00 from City Bank to bKash successful. Balance Tk 3,156.82. TrxID CJQ4AB12CD',
                ['provider' => 'bkash', 'direction' => 'in', 'amount' => 100000]],
            'bkash app payment' => ['bKash', 'Payment. Payment Tk 320.00 to Shwapno successful. Balance Tk 1,836.82. TrxID CJQ5PAY001',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 32000]],
            'bkash app received' => ['bKash', 'Received Money. You have received Tk 2,100.00 from 01811000000. Balance Tk 4,256.82. TrxID CJQ6REC001',
                ['provider' => 'bkash', 'direction' => 'in', 'amount' => 210000]],
            'bkash app cashback' => ['bKash', 'Cashback. You got Cashback Tk 10.00 for your Payment to Shwapno. Balance Tk 1,846.82. TrxID CJQ7CB0001',
                ['provider' => 'bkash', 'direction' => 'in', 'amount' => 1000]],
            'bkash app pay bill' => ['bKash', 'Pay Bill. Pay Bill Tk 1,250.00 to DESCO Postpaid successful. Balance Tk 596.82. TrxID CJQ8BILL01',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 125000]],
            'bkash app send money' => ['bKash', 'Send Money. Send Money Tk 500.00 to 01911000000 successful. Fee Tk 5.00. Balance Tk 91.82. TrxID CJQ9SEND01',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 50000, 'fee' => 500]],
            'nagad app reversal' => ['Nagad', 'Reversal. Tk 500.00 has been reversed to your Nagad account. Balance Tk 500.00. TxnID 73REV12CD',
                ['provider' => 'nagad', 'direction' => 'in', 'amount' => 50000]],
            'nagad failed reversed is nothing' => ['Nagad', 'Send Money of Tk 500.00 failed and reversed.', null],
            'rocket app cash out' => ['Rocket', 'Cash Out Tk 1,000.00 from A/C 01711000000 successful. Fee Tk 18.50. TxnId 1234567999',
                ['provider' => 'rocket', 'direction' => 'out', 'amount' => 100000]],
            'upay app received' => ['Upay', 'Received Money. You have received Tk 700.00 from 01611000000. TrxID UPAY77AB12',
                ['provider' => 'upay', 'direction' => 'in', 'amount' => 70000]],
            'nsave card payment' => ['nSave', 'Card payment approved. 💳 Paid $19.00 at FACEBK* Z25JQBJSD4 with your card ending 6903',
                ['provider' => 'nsave', 'direction' => 'out', 'amount' => 1900, 'currency' => 'USD', 'last4' => '6903']],
            'nsave notification' => ['nSave', 'You received $250.00 from Upwork. Your new balance is $1,240.10',
                ['provider' => 'nsave', 'direction' => 'in', 'amount' => 25000, 'currency' => 'USD', 'balance' => 124010]],
            'dbs card' => ['DBS', 'A card transaction of SGD 12.30 was made on your DBS card ending 5566 at FAIRPRICE XTRA on 24 Oct.',
                ['provider' => 'dbs', 'direction' => 'out', 'amount' => 1230, 'currency' => 'SGD', 'counterparty' => 'FAIRPRICE XTRA']],
            'airwallex email' => ['notifications@airwallex.com', "You've received SGD 1,000.00\nPayment from KLOK ADS PTE LTD has arrived in your Airwallex account.",
                ['provider' => 'airwallex', 'direction' => 'in', 'amount' => 100000, 'currency' => 'SGD']],
        ];
    }

    #[DataProvider('messages')]
    public function test_reads_common_alerts(string $sender, string $text, ?array $expected): void
    {
        $parsed = TransactionParser::parse($sender, $text);
        if ($expected === null) {
            $this->assertNull($parsed, 'no money moved');

            return;
        }

        $this->assertNotNull($parsed);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $parsed[$key], $key);
        }
    }

    public function test_skips_codes_offers_and_failures(): void
    {
        $this->assertNull(TransactionParser::parse('bKash', 'Your bKash verification code is 123456. Do not share it.'));
        $this->assertNull(TransactionParser::parse('GP', 'Get 20% off on 30GB pack for Tk 299 only! Dial *121#'));
        $this->assertNull(TransactionParser::parse('bKash', 'Send Money Tk 500.00 to 01811000000 failed. Insufficient balance.'));
        $this->assertNotNull(TransactionParser::parse('CityBank', 'Your shopping at DARAZ: A/C ...4321 debited by BDT 1,250.00'), '"shopping" is not "pin"');
    }

    public function test_sms_webhook_adds_the_entry_to_the_matching_account_once(): void
    {
        $bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'currency' => 'BDT', 'opening_balance' => 0]);
        $url = '/inbox/sms/'.AutoEntries::token();
        $sms = ['from' => 'bKash', 'text' => 'Send Money Tk 500.00 to 01811000000 successful. Ref Ammu. Fee Tk 5.00. Balance Tk 7,745.50. TrxID 8HG3WX1Y at 24/10/2026 12:01'];

        $this->postJson($url, $sms)->assertOk()->assertJson(['ok' => true, 'status' => 'added']);
        $this->postJson($url, $sms)->assertOk(); // forwarded twice

        $this->assertSame(1, MoneyTransaction::count());
        $this->assertSame(-50500, Ledger::balance($bkash), 'amount + fee');
        $this->assertStringContainsString('8HG3WX1Y', MoneyTransaction::sole()->description);

        $this->postJson('/inbox/sms/wrong', $sms)->assertNotFound();
        $this->post($url, ['message' => 'Your OTP is 4455'])->assertOk()->assertJson(['status' => 'skipped']);
    }

    public function test_unknown_account_waits_for_review_then_is_added_by_hand(): void
    {
        $this->actingAs(User::factory()->create());
        $one = Account::create(['name' => 'City 4321', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'city', 'currency' => 'BDT']);
        Account::create(['name' => 'City salary', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'city', 'currency' => 'BDT']);

        $m = app(AutoEntries::class)->ingest('sms', 'CityBank', 'Your A/C ...9999 has been credited by BDT 3,000.00 on 24-Oct-2026');
        $this->assertSame('review', $m->status, 'two City accounts, digits match neither');

        $this->post("/personal/inbox/{$m->id}/add", ['account_id' => $one->id, 'category' => 'family'])->assertSessionHas('success');
        $this->assertSame(['added', 'family'], [$m->fresh()->status, MoneyTransaction::sole()->category]);

        $this->post("/personal/inbox/{$m->id}/undo");
        $this->assertSame(0, MoneyTransaction::count());
        $this->assertSame('review', $m->fresh()->status);
    }

    public function test_money_in_pays_the_matching_invoice(): void
    {
        $bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'currency' => 'BDT']);
        $client = PersonalClient::create(['name' => 'Rafiq', 'phone' => '+880 1711-000000']);
        $invoice = app(PersonalInvoicing::class)->create($client, [['description' => 'Design', 'qty' => 1, 'rate' => 150000]], CarbonImmutable::today(), null);

        $m = app(AutoEntries::class)->ingest('sms', 'bKash', 'You have received Tk 1,500.00 from 01711000000. Ref INV. Fee Tk 0.00. Balance Tk 8,250.50. TrxID 9JK4XY2Z at 24/10/2026 10:15');

        $this->assertSame('matched', $m->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('sms', $invoice->payments()->sole()->source);
        $this->assertSame(150000, Ledger::balance($bkash));
        $this->assertSame(1, MoneyTransaction::count(), 'the invoice payment is the only entry');
    }

    public function test_a_reported_trxid_matches_even_with_another_amount_open(): void
    {
        Account::create(['name' => 'Nagad', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'nagad', 'currency' => 'BDT']);
        $client = PersonalClient::create(['name' => 'Karim']);
        $a = app(PersonalInvoicing::class)->create($client, [['description' => 'A', 'qty' => 1, 'rate' => 200000]], CarbonImmutable::today(), null);
        $b = app(PersonalInvoicing::class)->create($client, [['description' => 'B', 'qty' => 1, 'rate' => 200000]], CarbonImmutable::today(), null);
        $b->update(['reported' => ['method' => 'nagad', 'amount' => 200000, 'reference' => '73AB12CD']]);

        app(AutoEntries::class)->ingest('sms', 'NAGAD', 'Money Received. Amount: Tk 2000.00 Sender: 01911000000 TxnID: 73AB12CD Balance: Tk 5000.00');

        $this->assertSame(['unpaid', 'paid'], [$a->fresh()->status, $b->fresh()->status]);
    }

    public function test_reader_test_endpoint_and_inbox_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/personal/inbox-test', ['sender' => 'bKash', 'text' => 'You have received Tk 50.00 from 01711000000. TrxID ABC12345'])
            ->assertOk()->assertJsonPath('parsed.amount', 5000);

        InboxMessage::create(['source' => 'sms', 'sender' => 'x', 'body' => 'y', 'hash' => 'h', 'status' => 'review', 'parsed' => null]);
        $this->get('/personal/inbox')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/Inbox')->has('messages', 1)->where('setup.sms_url', fn ($u) => str_contains($u, '/inbox/sms/')));
    }

    public function test_metas_charge_on_a_card_is_ad_spend_on_the_right_nsave_and_never_counted_twice(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $main = Account::create(['name' => 'nSave Main', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD', 'opening_balance' => 10064]);
        $copy = Account::create(['name' => 'nSave Copy', 'scope' => 'business', 'kind' => 'wallet', 'provider' => 'nsave', 'currency' => 'USD', 'opening_balance' => 44766, 'app_profile' => 'clone']);
        $auto = app(AutoEntries::class);

        // From the second nSave app (a Klok Finance client's ads): business "Meta Ads spend".
        $auto->ingest('app', 'nSave', 'Card payment approved. 💳 Paid $19.00 at FACEBK* Z25JQBJSD4 with your card ending 6903', CarbonImmutable::parse('2026-10-25 18:35'), 'clone');
        $tx = MoneyTransaction::where('account_id', $copy->id)->sole();
        $this->assertSame(['expense', 1900, 'meta_ads', 'Meta'], [$tx->kind, $tx->amount, $tx->category, $tx->counterparty]);
        $this->assertSame(44766 - 1900, Ledger::balance($copy));

        // A client's charge already booked from Meta's log (৳ ÷ rate ≈ $21.02): the card's message is that one, with its exact amount.
        $booked = Ledger::save(['kind' => 'expense', 'account_id' => $main->id, 'amount' => 2102, 'category' => 'client_ads', 'counterparty' => 'Meta', 'occurred_on' => '2026-10-31']);
        $message = $auto->ingest('app', 'nSave', 'Card payment approved. 💳 Paid $21.05 at FACEBK* Q7PL2 with your card ending 5372', CarbonImmutable::parse('2026-10-31 15:00'));
        $this->assertSame('matched', $message->status);
        $this->assertSame(1, MoneyTransaction::where('account_id', $main->id)->count());
        $this->assertSame(2105, $booked->fresh()->amount);
    }

    public function test_a_wallet_can_be_an_sms_sender_and_an_app_at_once(): void
    {
        $rules = InboxRules::save(['senders' => [['name' => 'bKash', 'type' => 'sms'], ['name' => 'bKash', 'type' => 'app'], ['name' => 'bkash', 'type' => 'app']]]);

        $this->assertSame([['bKash', 'sms'], ['bKash', 'app']], array_map(fn ($s) => [$s['name'], $s['type']], $rules['senders']));
    }

    public function test_a_failed_transfer_coming_back_is_not_a_clients_payment_and_ucb_matches_its_statement(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $ucb = Account::create(['name' => 'UCB', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'last4' => '6318', 'currency' => 'BDT', 'opening_balance' => 10033200]);
        $client = PersonalClient::create(['name' => 'Tasfia Engineering']);
        $invoice = app(PersonalInvoicing::class)->create($client, [['description' => 'Work', 'qty' => 1, 'rate' => 10000000]], CarbonImmutable::today(), null);
        $auto = app(AutoEntries::class);
        $sms = fn (string $text, string $at) => $auto->ingest('sms', 'UCB', $text, CarbonImmutable::parse("2026-10-29 {$at}", 'Asia/Dhaka'));

        // 29 Oct: ৳1,00,000 by NPSB, it bounced and came back, then it went again (UCB's own SMS, in order).
        $sms('Your A/C (***6318) has been debited BDT 1,00,000.00 for I Banking NPSB Transfer Debit. Avl Bal: BDT 322.00 @ 12:02 PM. For query: 16419', '12:02:11');
        $sms('Your A/C (***6318) has been debited BDT 8.70 for NPSB CHARGE. Avl Bal: BDT 322.00 @ 12:02 PM. For query: 16419', '12:02:22');
        $sms('Your A/C (***6318) has been credited (reversed) BDT 8.70 for NPSB CHARGE. Avl Bal: BDT 1,00,332.00 @ 12:02 PM. For query: 16419', '12:02:30');
        $back = $sms('Your A/C (***6318) has been credited (reversed) BDT 1,00,000.00 for I Banking NPSB Transfer Debit. Avl Bal: BDT 1,00,332.00 @ 12:02 PM. For query: 16419', '12:02:37');
        $sms('Your A/C (***6318) has been debited BDT 8.70 for NPSB CHARGE. Avl Bal: BDT 322.00 @ 12:17 PM. For query: 16419', '12:17:24');
        $sms('Your A/C (***6318) has been debited BDT 1,00,000.00 for I Banking NPSB Transfer Debit. Avl Bal: BDT 322.00 @ 12:17 PM. For query: 16419', '12:17:26');

        $this->assertSame('added', $back->status, 'my own money back, not a payment');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('Reversal', MoneyTransaction::find($back->money_transaction_id)->counterparty);
        // The NPSB fee is ৳10 (৳8.70 + 15% VAT the bank sends no SMS for): the books say what the bank says.
        $this->assertSame(32200, Ledger::balance($ucb));
        $this->assertSame([1000, 1000], MoneyTransaction::where('category', 'bank_fees')->pluck('amount')->all(), 'two ৳10 fees…');
        $this->assertSame(1000, (int) MoneyTransaction::where('kind', 'income')->where('amount', 1000)->sum('amount'), '…and the first one back');
    }

    public function test_a_small_gap_with_the_banks_balance_is_a_bank_charge_and_a_big_one_is_told(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $small = Account::create(['name' => 'City Bank', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'city', 'last4' => '0001', 'currency' => 'BDT', 'opening_balance' => 100000, 'bank_balance' => 98850, 'bank_balance_at' => now()->subMinutes(20)]);
        $big = Account::create(['name' => 'Midland', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'other', 'last4' => '2093', 'currency' => 'BDT', 'opening_balance' => 500000, 'bank_balance' => 400000, 'bank_balance_at' => now()->subMinutes(20)]);

        $this->assertSame(1, app(AutoEntries::class)->reconcile());
        $this->assertSame(98850, Ledger::balance($small->fresh()));
        $this->assertSame(500000, Ledger::balance($big->fresh()), 'a big gap is never guessed');
        $this->assertSame(1, AppNotification::where('title', 'like', '⚖️ Midland%')->count());
        app(AutoEntries::class)->reconcile();
        $this->assertSame(1, AppNotification::where('title', 'like', '⚖️ Midland%')->count(), 'told once');

        // One tap: the books say what the bank says.
        $this->post("/money/accounts/{$big->id}/match-bank")->assertSessionHas('success');
        $this->assertSame(400000, Ledger::balance($big->fresh()));
    }
}
