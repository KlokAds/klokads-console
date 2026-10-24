<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InboxMessage;
use App\Models\MoneyTransaction;
use App\Models\PersonalClient;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\PersonalInvoicing;
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
                ['provider' => 'bkash', 'direction' => 'in', 'amount' => 150000, 'currency' => 'BDT', 'reference' => '9JK4XY2Z']],
            'bkash send money' => ['bKash', 'Send Money Tk 500.00 to 01811000000 successful. Ref Ammu. Fee Tk 5.00. Balance Tk 7,745.50. TrxID 8HG3WX1Y at 24/10/2026 12:01',
                ['provider' => 'bkash', 'direction' => 'out', 'amount' => 50000, 'fee' => 500, 'reference' => '8HG3WX1Y']],
            'nagad received' => ['NAGAD', 'Money Received. Amount: Tk 2000.00 Sender: 01911000000 Ref: N/A TxnID: 73AB12CD Balance: Tk 5000.00 24/10/2026 09:10',
                ['provider' => 'nagad', 'direction' => 'in', 'amount' => 200000, 'reference' => '73AB12CD']],
            'rocket' => ['16216', 'Tk1,000.00 received from A/C:017110000001 Fee:Tk0, Your A/C Balance: Tk3,500.00 TxnId:1234567890 Date:24-OCT-26 10:01:02 am',
                ['provider' => 'rocket', 'direction' => 'in', 'amount' => 100000, 'reference' => '1234567890']],
            'city bank credit' => ['CityBank', 'Dear Customer, your A/C ...4321 has been credited by BDT 25,000.00 on 24-Oct-2026 (NPSB). Avl Bal: BDT 60,210.00',
                ['provider' => 'city', 'direction' => 'in', 'amount' => 2500000, 'last4' => '4321']],
            'dbs card' => ['DBS', 'A card transaction of SGD 12.30 was made on your DBS card ending 5566 at FAIRPRICE XTRA on 24 Oct.',
                ['provider' => 'dbs', 'direction' => 'out', 'amount' => 1230, 'currency' => 'SGD', 'counterparty' => 'FAIRPRICE XTRA']],
            'airwallex email' => ['notifications@airwallex.com', "You've received SGD 1,000.00\nPayment from KLOK ADS PTE LTD has arrived in your Airwallex account.",
                ['provider' => 'airwallex', 'direction' => 'in', 'amount' => 100000, 'currency' => 'SGD']],
        ];
    }

    #[DataProvider('messages')]
    public function test_reads_common_alerts(string $sender, string $text, array $expected): void
    {
        $parsed = TransactionParser::parse($sender, $text);

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
}
