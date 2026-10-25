<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\MoneyTransaction;
use App\Models\PersonalAdBill;
use App\Models\PersonalAdCharge;
use App\Models\PersonalAdDay;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdPerformance;
use App\Services\PersonalAds;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Edge Education's and Marine Orvion's ads: whose campaigns, spend in their currency, controls, and bills that never overlap. */
class PersonalAdsTest extends TestCase
{
    use RefreshDatabase;

    private PersonalClient $edge;

    private PersonalClient $marine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        Setting::put('personal_timezone', 'Asia/Dhaka');
        Setting::put('pilot_url', 'https://pilot.test');
        Setting::put('pilot_bridge_key', 'bridge-key');
        Setting::put('klokone_fx_rate', '129.83'); // KlokOne's dollar rate (last known)
        CarbonImmutable::setTestNow('2026-11-01 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->edge = PersonalClient::create(['name' => 'Edge Education']);
        $this->marine = PersonalClient::create(['name' => 'Marine Orvion']);
    }

    private function fakePilot(string $edgeStatus = 'ACTIVE', bool $billing = true): void
    {
        if ($billing) {
            Http::fake([
                'pilot.test/internal/console/ad-accounts/*/billing' => Http::response(['data' => ['currency' => 'BDT', 'amount_spent' => 1000000, 'unbilled' => 0, 'card' => 'VISA *5372']]),
                'pilot.test/internal/console/ad-accounts/*/charges*' => Http::response(['data' => []]),
            ]);
        }
        Http::preventStrayRequests();
        Http::fake([
            'pilot.test/internal/console/ad-accounts' => Http::response(['data' => [
                ['id' => 'act_1', 'name' => 'Edge Meta Account', 'currency' => 'BDT', 'client' => 'Edge'],
                ['id' => 'act_2', 'name' => 'Eazy Mall', 'currency' => 'USD', 'client' => 'Eazy Mall'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_1/campaigns' => Http::response(['data' => [
                ['id' => '11', 'name' => 'Edge Leads', 'status' => $edgeStatus, 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 150000, 'start' => '2026-10-01', 'end' => '2026-11-30'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/campaigns' => Http::response(['data' => [
                ['id' => '21', 'name' => 'Marine Orvion Awareness', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_AWARENESS', 'daily_budget' => 1000, 'start' => null, 'end' => null],
                ['id' => '22', 'name' => 'Eazy Mall own', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => 500, 'start' => null, 'end' => null],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_1/spend*' => Http::response(['data' => [
                ['campaign_id' => '11', 'campaign_name' => 'Edge Leads', 'date' => '2026-10-10', 'spend' => 120000, 'impressions' => 9000, 'clicks' => 200, 'reach' => 7000, 'results' => 12, 'result_type' => 'lead'],
                ['campaign_id' => '11', 'campaign_name' => 'Edge Leads', 'date' => '2026-10-20', 'spend' => 80000, 'impressions' => 6000, 'clicks' => 100, 'reach' => 5000, 'results' => 8, 'result_type' => 'lead'],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/spend*' => Http::response(['data' => [
                ['campaign_id' => '21', 'campaign_name' => 'Marine Orvion Awareness', 'date' => '2026-10-15', 'spend' => 1500, 'impressions' => 3000, 'clicks' => 9, 'reach' => 2500, 'results' => 1800, 'result_type' => 'video_view'],
                ['campaign_id' => '22', 'campaign_name' => 'Eazy Mall own', 'date' => '2026-10-15', 'spend' => 999, 'impressions' => 10, 'clicks' => 1, 'reach' => 10, 'results' => 0, 'result_type' => null],
            ]]),
            'pilot.test/internal/console/ad-accounts/act_2/campaigns/21/limits' => Http::response(['data' => ['active' => false, 'daily_budget' => 1000, 'end' => null, 'extendable' => true, 'why_not' => null]]),
        ]);
    }

    private function assignBoth(): void
    {
        $this->post("/personal/client-ads/{$this->edge->id}/assign", ['account_id' => 'act_1', 'whole' => true, 'paid_by' => 'me', 'from' => '2026-10-01'])->assertSessionHas('success');
        $this->post("/personal/client-ads/{$this->marine->id}/assign", ['account_id' => 'act_2', 'whole' => false, 'campaigns' => ['21'], 'paid_by' => 'me', 'from' => '2026-10-01'])->assertSessionHas('success');
    }

    public function test_a_whole_account_or_only_some_campaigns_belong_to_a_client_in_its_own_currency(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->assertSame(200000, (int) PersonalAdDay::where('personal_client_id', $this->edge->id)->sum('spend'));
        $this->assertSame(['BDT'], PersonalAdDay::where('personal_client_id', $this->edge->id)->pluck('currency')->unique()->values()->all());
        $marine = PersonalAdDay::where('personal_client_id', $this->marine->id)->get();
        $this->assertSame(['21'], $marine->pluck('campaign_id')->unique()->values()->all(), 'the account’s other campaign is not Marine’s');
        $this->assertSame('USD', $marine->first()->currency);

        // A campaign already someone's can't be given to another client.
        $this->post("/personal/client-ads/{$this->edge->id}/assign", ['account_id' => 'act_2', 'whole' => false, 'campaigns' => ['21'], 'paid_by' => 'me', 'from' => '2026-10-01'])
            ->assertSessionHas('error');
    }

    public function test_results_follow_the_campaign_goal(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->get("/personal/client-ads/{$this->marine->id}?period=lastmonth")->assertInertia(fn ($page) => $page->component('Personal/AdClients/Show')
            ->where('campaigns.0.goal', 'Awareness')->where('campaigns.0.results', 2500)->where('campaigns.0.result_label', 'people reached')
            ->where('campaigns.0.cost_per_result', 600)->where('campaigns.0.per', 'per 1,000 reached')->where('money.USD.my_card', 1500));
        $this->get("/personal/client-ads/{$this->edge->id}?period=lastmonth")->assertInertia(fn ($page) => $page
            ->where('campaigns.0.goal', 'Leads')->where('campaigns.0.results', 20)->where('campaigns.0.per', 'per lead')->where('campaigns.0.cost_per_result', 10000));
        $this->assertSame('Messages', AdPerformance::goal('OUTCOME_ENGAGEMENT', 'onsite_conversion.messaging_conversation_started_7d'));
    }

    public function test_bills_take_only_days_not_billed_before_so_nothing_is_billed_twice(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $this->edge->update(['ad_billed_until' => '2026-10-15', 'ad_bill_basis' => 'spend']); // settled up to 15 Oct, from my sheet
        $this->marine->update(['ad_bill_basis' => 'spend']);

        // 2 Nov: the unbilled days of last month → Edge 16–31 Oct (only the 20 Oct spend), Marine in USD.
        $this->assertSame(0, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Dhaka')), 'not on the 1st: Meta still corrects');
        $this->assertSame(2, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Dhaka')));
        $bill = PersonalAdBill::where('personal_client_id', $this->edge->id)->sole();
        $this->assertSame(['2026-10-16', '2026-10-31'], [$bill->period_from->toDateString(), $bill->period_to->toDateString()]);
        // BDT 800 ÷ 119 = $6.72 → × 129.83 = BDT 872.81 → + 20%.
        $taka = (int) round(80000 / 119 * 129.83);
        $this->assertSame($taka + (int) round($taka * 0.2), $bill->invoice->total, 'in dollars at the agency rate, + 20% commission');
        $this->assertSame('BDT', $bill->invoice->currency);
        $this->assertCount(1, $bill->invoice->items, 'the campaign that spent, no rate, no commission');
        $this->assertSame('Edge Leads — 16 Oct – 31 Oct 2026', $bill->invoice->items[0]['description']);
        $this->assertNull($bill->invoice->note);
        $this->assertNull($bill->invoice->sent_at, 'drafted, not sent');
        // Marine's account is in dollars: $15 × 129.83 + 20%, billed in taka too.
        $this->assertSame((int) round(1500 * 129.83 * 1.2), PersonalAdBill::where('personal_client_id', $this->marine->id)->sole()->invoice->total);

        // Again, or "Bill now" up to today: nothing new — those days are billed.
        $this->assertSame(0, app(PersonalAds::class)->bills(CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Dhaka')));
        $this->post("/personal/client-ads/{$this->edge->id}/bill", ['to' => '2026-11-01'])->assertSessionHas('error'); // today is not finished
        $this->assertSame(2, PersonalInvoice::count());

        $ledger = PersonalAds::ledger($this->edge->fresh());
        $this->assertSame($bill->invoice->total, $ledger['totals']['BDT']['due']);
        $this->assertSame('2026-11-01', $ledger['unbilled_from']);
    }

    public function test_a_campaign_opens_in_full_with_its_days(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->get("/personal/client-ads/{$this->edge->id}/campaigns/11?period=all")->assertInertia(fn ($page) => $page->component('Personal/AdClients/Campaign')
            ->where('campaign.name', 'Edge Leads')->where('campaign.goal', 'Leads')->where('totals.spend', 200000)->where('totals.results', 20)
            ->where('days', fn ($days) => collect($days)->firstWhere('date', '2026-10-20')['spend'] === 80000));
        $this->get("/personal/client-ads/{$this->edge->id}/campaigns/999")->assertNotFound();
    }

    public function test_campaign_controls_go_to_meta_and_the_page_shows_the_new_state(): void
    {
        $this->fakePilot();
        $this->assignBoth();

        $this->post("/personal/client-ads/{$this->marine->id}/campaigns/21", ['action' => 'pause'])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/campaigns/21/limits') && $r['active'] === false && $r->hasHeader('X-Console-Key', 'bridge-key'));
        $this->assertSame('PAUSED', $this->marine->fresh()->ad_campaigns[21]['status']);
        $this->post("/personal/client-ads/{$this->marine->id}/campaigns/22", ['action' => 'pause'])->assertNotFound();
    }

    public function test_the_client_report_link_shows_their_campaigns_and_nothing_about_my_card(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $token = PersonalAds::token($this->edge->fresh());
        auth()->logout();

        $this->get("/ads/{$token}?period=lastmonth")->assertOk()->assertInertia(fn ($page) => $page->component('Personal/AdClients/Report')
            ->where('name', 'Edge Education')->where('totals.BDT.spend', 200000)->where('campaigns.0.results', 20)
            ->where('campaigns.0', fn ($c) => ! isset($c['paid_by']) && ! isset($c['account'])));
        $this->get('/ads/wrong-token')->assertNotFound();
    }

    public function test_a_change_in_a_campaigns_state_is_told(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $snapshot = $this->edge->fresh()->ad_campaigns;
        $snapshot[11]['status'] = 'PAUSED'; // as it was before; Meta now says ACTIVE
        $this->edge->update(['ad_campaigns' => $snapshot]);

        app(PersonalAds::class)->sync(force: true);

        $this->assertSame(1, AppNotification::where('title', 'Edge Education: “Edge Leads” ▶️ running again')->count());
    }

    public function test_payments_any_amount_pay_the_oldest_bills_and_the_rest_is_credit_for_the_next(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $this->edge->update(['ad_bill_basis' => 'charges']);
        $ucb = Account::create(['name' => 'UCB', 'scope' => 'personal', 'kind' => 'bank', 'provider' => 'ucb', 'currency' => 'BDT']);

        $this->post("/personal/client-ads/{$this->edge->id}/charges", ['date' => '2026-10-24', 'amount' => '2501.94', 'currency' => 'BDT']);
        $this->post("/personal/client-ads/{$this->edge->id}/bill");
        $first = PersonalAdBill::where('personal_client_id', $this->edge->id)->sole()->invoice;

        // They pay more than the bill: the bill is paid, the rest is credit; the money lands once in UCB.
        $this->post("/personal/client-ads/{$this->edge->id}/payments", ['date' => '2026-10-31', 'amount' => '5000', 'account_id' => $ucb->id])->assertSessionHas('success');
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame(500000 - $first->total, PersonalAds::credit($this->edge->fresh()));
        $this->assertSame(500000, (int) MoneyTransaction::where('account_id', $ucb->id)->sum('amount'), 'booked once, not again per bill');
        // The payment shows what it paid; the charge shows it is paid.
        $ledger = PersonalAds::ledger($this->edge->fresh());
        $this->assertSame($first->number, $ledger['payments'][0]['paid'][0]['number']);
        $this->assertSame($first->total, $ledger['payments'][0]['paid'][0]['amount']);
        $this->assertSame('paid', $ledger['charges'][0]['state']);

        // The next bill takes the credit first.
        $this->post("/personal/client-ads/{$this->edge->id}/charges", ['date' => '2026-10-31', 'amount' => '10', 'currency' => 'USD']);
        $this->post("/personal/client-ads/{$this->edge->id}/bill");
        $second = PersonalAdBill::where('personal_client_id', $this->edge->id)->latest('id')->first()->invoice->fresh();
        $this->assertSame(min($second->total, 500000 - $first->total), $second->paid);
        $this->assertSame(500000, (int) MoneyTransaction::where('account_id', $ucb->id)->sum('amount'));
    }

    public function test_metas_card_charges_come_from_its_log_once_each_and_settled_days_are_skipped(): void
    {
        $this->fakePilot(billing: false);
        Http::fake([
            'pilot.test/internal/console/ad-accounts/*/billing' => Http::response(['data' => ['currency' => 'BDT', 'amount_spent' => 1000000, 'unbilled' => 66322, 'card' => 'VISA *5372']]),
            'pilot.test/internal/console/ad-accounts/act_1/charges*' => Http::response(['data' => [
                ['id' => 'tx-old', 'time' => '2026-10-01T14:46:10+0000', 'amount' => 358572, 'currency' => 'BDT'],
                ['id' => 'tx-new', 'time' => '2026-10-21T00:13:05+0000', 'amount' => 250194, 'currency' => 'BDT'],
            ]]),
            'pilot.test/internal/console/ad-accounts/*/charges*' => Http::response(['data' => []]),
        ]);
        $this->edge->update(['ad_billed_until' => '2026-10-20']); // paid up to 20 Oct
        $this->assignBoth();
        app(PersonalAds::class)->sync(force: true);

        $charges = PersonalAdCharge::where('personal_client_id', $this->edge->id)->get();
        $this->assertSame(['tx-new'], $charges->pluck('meta_id')->all(), 'the 1 Oct charge is settled already');
        $this->assertSame('2026-10-21', $charges->first()->date->toDateString());
        $this->assertSame(250194, $charges->first()->amount);
        $this->assertSame('charges', $this->edge->fresh()->ad_bill_basis, 'my card: billed by charges, like Edge');
        $this->assertSame(1, AppNotification::where('title', 'like', '💳 Meta charged your card for Edge Education%')->count());
        $this->assertSame(0, PersonalAdCharge::where('personal_client_id', $this->marine->id)->count());
    }

    public function test_metas_charges_are_spending_on_my_card_account_and_what_is_not_charged_yet_shows(): void
    {
        $later = false;
        $this->fakePilot(billing: false);
        Http::fake([
            'pilot.test/internal/console/ad-accounts/*/billing' => Http::response(['data' => ['currency' => 'BDT', 'amount_spent' => 1000000, 'unbilled' => 67510, 'card' => 'VISA *5372']]),
            // Meta's log: the 21 Oct charge; later a new one too.
            'pilot.test/internal/console/ad-accounts/act_1/charges*' => function () use (&$later) {
                return Http::response(['data' => array_values(array_filter([
                    ['id' => 'tx-new', 'time' => '2026-10-21T00:13:05+0000', 'amount' => 250194, 'currency' => 'BDT'],
                    $later ? ['id' => 'tx-next', 'time' => '2026-10-31T09:00:00+0000', 'amount' => 119000, 'currency' => 'BDT'] : null,
                ]))]);
            },
            'pilot.test/internal/console/ad-accounts/*/charges*' => Http::response(['data' => []]),
        ]);
        $nsave = Account::create(['name' => 'nSave Main', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'USD', 'opening_balance' => 10064]);
        $this->edge->update(['ad_billed_until' => '2026-10-20', 'ad_meta_rate' => 119]);
        $this->assignBoth();
        app(PersonalAds::class)->sync(force: true);

        // Spent but not charged yet: Meta's ৳675.10, and roughly what it comes to on their bill.
        $this->get("/personal/client-ads/{$this->edge->id}?tab=money")->assertInertia(fn ($page) => $page
            ->where('ledger.meta.0.unbilled', 67510)->where('ledger.meta.0.bill', 88385)); // ৳675.10 ÷ 119 × 129.83 + 20%

        // nSave is the card: the charge so far is spending there; the balance I keep stays $100.64.
        $edge = $this->edge->fresh();
        $this->put("/personal/client-ads/{$edge->id}/settings", ['ad_bill' => false, 'ad_fee_percent' => 20, 'billed_until' => '2026-10-20',
            'ad_bill_basis' => 'charges', 'ad_meta_rate' => 119, 'ad_card_account_id' => $nsave->id])->assertSessionHas('success');
        $tx = MoneyTransaction::where('account_id', $nsave->id)->sole();
        $this->assertSame(['expense', 2102, 'client_ads', '2026-10-21'], [$tx->kind, $tx->amount, $tx->category, $tx->occurred_on->toDateString()]);
        $this->assertSame(10064, Ledger::balance($nsave->fresh()));
        $this->assertSame($tx->id, PersonalAdCharge::where('meta_id', 'tx-new')->value('money_transaction_id'));

        // Saving again adds nothing twice; a new charge from Meta comes off the balance.
        $this->put("/personal/client-ads/{$edge->id}/settings", ['ad_bill' => false, 'ad_fee_percent' => 20, 'billed_until' => '2026-10-20',
            'ad_bill_basis' => 'charges', 'ad_meta_rate' => 119, 'ad_card_account_id' => $nsave->id]);
        $later = true;
        app(PersonalAds::class)->sync(force: true);
        $this->assertSame(2, MoneyTransaction::where('account_id', $nsave->id)->count());
        $this->assertSame(10064 - 1000, Ledger::balance($nsave->fresh()));
    }

    public function test_billed_by_each_card_charge_i_enter_in_taka_or_dollars(): void
    {
        $this->fakePilot();
        $this->assignBoth();
        $this->edge->update(['ad_bill_basis' => 'charges', 'ad_meta_rate' => 119]);

        // Edge's last charge: BDT 2,501.94 → ÷ 119 = $21.02 → × 129.83 = BDT 2,729.65 → + 20% = BDT 3,275.58.
        $this->post("/personal/client-ads/{$this->edge->id}/charges", ['date' => '2026-10-24', 'amount' => '2501.94', 'currency' => 'BDT'])->assertSessionHas('success');
        $ledger = PersonalAds::ledger($this->edge->fresh());
        $taka = (int) round(250194 / 119 * 129.83);
        $this->assertSame($taka + (int) round($taka * 0.2), $ledger['unbilled_taka']['with_fee']);

        $this->post("/personal/client-ads/{$this->edge->id}/bill")->assertSessionHas('success');
        $invoice = PersonalAdBill::where('personal_client_id', $this->edge->id)->sole()->invoice;
        $this->assertSame($taka + (int) round($taka * 0.2), $invoice->total);
        $this->assertStringNotContainsString('119', $invoice->items[0]['description'], 'my rate stays with me');
        $this->assertStringNotContainsString('129', $invoice->items[0]['description']);
        $this->assertSame('$21.02 × 129.83 + 20%', PersonalAds::ledger($this->edge->fresh())['bills'][0]['how']);
        // Inside the app: the invoice, its receipt, and the working for me only.
        $this->get("/personal/invoices/{$invoice->id}")->assertInertia(fn ($page) => $page->component('Personal/InvoiceView')
            ->where('invoice.number', $invoice->number)->where('working.how', '$21.02 × 129.83 + 20% commission')->has('payments', 0));

        // A charge entered in dollars (what the card was cut): straight × rate + 20%. The first is not billed again.
        $this->post("/personal/client-ads/{$this->edge->id}/charges", ['date' => '2026-10-31', 'amount' => '10', 'currency' => 'USD']);
        $this->post("/personal/client-ads/{$this->edge->id}/bill");
        $second = PersonalAdBill::where('personal_client_id', $this->edge->id)->latest('id')->first()->invoice;
        $this->assertSame((int) round(1000 * 129.83 * 1.2), $second->total);
        $this->assertSame(2, PersonalAdBill::where('personal_client_id', $this->edge->id)->count());
    }
}
