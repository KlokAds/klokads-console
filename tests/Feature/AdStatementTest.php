<?php

namespace Tests\Feature;

use App\Mail\StatementMail;
use App\Models\AirwallexEntry;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\ClientStatement;
use App\Models\MetaSpendDay;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdStatements;
use App\Services\Airwallex;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Tasfia's monthly statement (drafted, checked, sent) and Meta's charges checked against the spend. */
class AdStatementTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        CarbonImmutable::setTestNow('2026-11-01 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->client = Client::create(['name' => 'Tasfia Engineering', 'company' => 'Tasfia Engineering', 'email' => 'billing@tasfiaengineering.com',
            'meta_ad_account_id' => 'act_808', 'billing_mode' => 'prepaid', 'currency' => 'sgd']);

        ClientBalanceEntry::create(['client_id' => $this->client->id, 'kind' => 'manual', 'amount' => 20352, 'date' => '2026-10-25', 'note' => 'Opening balance']);
        ClientBalanceEntry::create(['client_id' => $this->client->id, 'kind' => 'top_up', 'amount' => 50000, 'date' => '2026-10-28', 'note' => 'KA-0012']);
        foreach (['2026-10-26' => [3000, 1000], '2026-10-27' => [2000, 0]] as $date => [$a, $b]) {
            MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c1', 'campaign_name' => 'Handyman Traffic', 'date' => $date, 'spend' => $a, 'impressions' => 1000, 'clicks' => 20, 'currency' => 'SGD']);
            if ($b) {
                MetaSpendDay::create(['client_id' => $this->client->id, 'campaign_id' => 'c2', 'campaign_name' => 'Painting Leads', 'date' => $date, 'spend' => $b, 'impressions' => 300, 'clicks' => 4, 'currency' => 'SGD']);
            }
            ClientBalanceEntry::create(['client_id' => $this->client->id, 'kind' => 'spend', 'amount' => -(int) round(($a + $b) * 1.2), 'spend' => $a + $b, 'commission' => (int) round(($a + $b) * 0.2), 'date' => $date]);
        }
    }

    public function test_last_months_statement_is_drafted_on_the_first_and_i_am_asked_once(): void
    {
        $statements = app(AdStatements::class);
        $this->assertSame(1, $statements->draftMonthly());
        $this->assertSame(0, $statements->draftMonthly(), 'once');

        $s = ClientStatement::sole();
        $this->assertSame('2026-10-01', $s->period_start->toDateString());
        $this->assertSame('draft', $s->status);
        $d = $s->data;
        $this->assertSame(0, $d['opening']);
        $this->assertSame(20352 + 50000 - 7200, $d['closing']);
        $this->assertSame(6000, $d['spend']);
        $this->assertSame(1200, $d['fee']);
        $this->assertSame(['Handyman Traffic', 'Painting Leads'], array_column($d['campaigns'], 'name'));
        $this->assertSame(1, AppNotification::where('title', 'like', '📄 Tasfia Engineering: October statement ready')->count());

        // With the fee on its own line, or folded into the campaigns: the same total either way.
        $this->assertSame(7200, array_sum(array_column(AdStatements::lines($d, true), 'amount')));
        $folded = AdStatements::lines($d, false);
        $this->assertSame(7200, array_sum(array_column($folded, 'amount')));
        $this->assertSame(['Handyman Traffic', 'Painting Leads'], array_column($folded, 'label'));
    }

    public function test_i_check_it_then_send_it_and_it_is_not_sent_twice(): void
    {
        Mail::fake();
        Setting::put('smtp_host', 'smtp.example.com');
        Setting::put('smtp_username', 'u');
        Setting::put('smtp_password', 'p');
        Setting::put('mail_from_address', 'billing@klokads.com');

        $this->post('/statements', ['client' => $this->client->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertRedirect();
        $s = ClientStatement::sole();
        $this->get("/statements/{$s->id}")->assertInertia(fn ($page) => $page->component('Statements/Show')->where('statement.data.spend', 6000));
        $this->put("/statements/{$s->id}", ['show_fee' => false]);
        $this->post("/statements/{$s->id}/send")->assertSessionHas('success');

        Mail::assertSent(StatementMail::class, fn ($mail) => $mail->hasTo('billing@tasfiaengineering.com') && ! $mail->statement->show_fee);
        $this->assertSame('sent', $s->fresh()->status);
        $this->post("/statements/{$s->id}/send")->assertStatus(409);
    }

    public function test_without_email_set_up_nothing_is_sent(): void
    {
        $s = app(AdStatements::class)->draft($this->client, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->post("/statements/{$s->id}/send")->assertSessionHas('error');
        $this->assertSame('draft', $s->fresh()->status);
    }

    public function test_a_meta_charge_bigger_than_the_spend_since_the_last_charge_is_flagged_once(): void
    {
        AirwallexEntry::create(['airwallex_id' => 'it:1', 'kind' => 'meta', 'amount' => -4000, 'currency' => 'SGD', 'occurred_at' => '2026-10-20 06:00', 'client_id' => $this->client->id]);
        AirwallexEntry::create(['airwallex_id' => 'it:2', 'kind' => 'meta', 'amount' => -5500, 'currency' => 'SGD', 'occurred_at' => '2026-10-28 06:00', 'client_id' => $this->client->id]);
        $airwallex = app(Airwallex::class);

        $airwallex->checkMetaCharges($this->client);
        $airwallex->checkMetaCharges($this->client);

        // 20 Oct: S$40 charged, S$0 spent in the 60 days before → flagged. 28 Oct: S$55 for S$60 of spend → fine.
        $flags = AppNotification::where('title', '🔎 Meta charged more than the ads spent')->get();
        $this->assertCount(1, $flags);
        $this->assertStringContainsString('On 20 Oct', $flags->first()->body);
    }
}
