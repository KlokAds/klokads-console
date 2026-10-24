<?php

namespace Tests\Feature;

use App\Mail\PersonalInvoiceMail;
use App\Models\Account;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\PersonalRecurring;
use App\Models\Setting;
use App\Models\User;
use App\Services\PersonalInvoicing;
use App\Support\Ledger;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PersonalInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private PersonalClient $client;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Hasan']));
        $this->client = PersonalClient::create(['name' => 'Rafiq', 'company' => 'Rafiq Traders', 'phone' => '01711000000', 'email' => 'rafiq@example.com']);
        $this->bkash = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'provider' => 'bkash', 'currency' => 'BDT']);
    }

    private function invoice(): PersonalInvoice
    {
        $this->post('/personal/invoices', [
            'personal_client_id' => $this->client->id, 'issued_on' => '2026-10-24', 'due_on' => '2026-10-31', 'send' => false,
            'items' => [['description' => 'Website design', 'qty' => 1, 'rate' => 15000], ['description' => 'Hosting', 'qty' => 2, 'rate' => 1250]],
        ])->assertSessionHasNoErrors();

        return PersonalInvoice::latest('id')->first();
    }

    public function test_invoice_totals_in_bdt_and_a_payment_lands_in_the_account(): void
    {
        $inv = $this->invoice();
        $this->assertSame('INV-0001', $inv->number);
        $this->assertSame(1750000, $inv->total);

        $this->post("/personal/invoices/{$inv->id}/payments", ['amount' => 10000, 'paid_on' => '2026-10-25', 'method' => 'bkash', 'reference' => 'TRX1', 'account_id' => $this->bkash->id])->assertSessionHasNoErrors();
        $this->assertSame('partial', $inv->fresh()->status);
        $this->assertSame(1000000, Ledger::balance($this->bkash));

        $this->post("/personal/invoices/{$inv->id}/payments", ['amount' => 7500, 'paid_on' => '2026-10-26', 'method' => 'nagad'])->assertSessionHasNoErrors();
        $this->assertSame(['paid', '2026-10-26'], [$inv->fresh()->status, $inv->fresh()->paid_on->toDateString()]);

        $this->get('/personal')->assertInertia(fn ($page) => $page->where('stats.BDT.current.in', 1000000));
    }

    public function test_client_reports_payment_and_it_waits_on_hold(): void
    {
        $inv = $this->invoice();
        auth()->logout();

        $this->get("/pi/{$inv->public_token}")->assertOk()->assertInertia(fn ($page) => $page->component('Public/PersonalInvoice')->where('invoice.due', 1750000));
        $this->post("/pi/{$inv->public_token}/paid", ['method' => 'bkash', 'amount' => 17500, 'reference' => '9JK4XY2Z', 'from' => '01711000000'])->assertSessionHas('success');

        $inv->refresh();
        $this->assertSame('unpaid', $inv->status, 'not paid until confirmed');
        $this->assertSame(['bkash', 1750000, '9JK4XY2Z'], [$inv->reported['method'], $inv->reported['amount'], $inv->reported['reference']]);

        $this->post("/pi/{$inv->public_token}/paid", ['method' => 'bkash', 'amount' => 999999])->assertSessionHasErrors('amount');
        $this->get('/pi/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_email_with_pdf_and_payment_details(): void
    {
        Mail::fake();
        Setting::put('smtp_host', 'smtp.example.com');
        Setting::put('smtp_username', 'billing@klokads.com');
        Setting::put('smtp_password', 'secret');
        Setting::put('mail_from_address', 'billing@klokads.com');

        $this->post('/personal/invoice-profile', [
            'name' => 'Hasan Ahmed', 'phone' => '01700000000',
            'methods' => [['type' => 'bkash', 'number' => '01700000000', 'name' => 'Hasan Ahmed', 'extra' => 'Personal — Send Money']],
            'qr' => UploadedFile::fake()->image('qr.png', 200, 200),
        ])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('data:image/png;base64,', Setting::get('personal_qr'));

        $inv = $this->invoice();
        $this->post("/personal/invoices/{$inv->id}/send");

        if (MailSettings::configured()) {
            Mail::assertSent(PersonalInvoiceMail::class, fn ($m) => $m->hasTo('rafiq@example.com'));
            $this->assertNotNull($inv->fresh()->sent_at);
        }

        $html = view('personal.invoice-pdf', ['invoice' => $inv->fresh(), 'me' => PersonalInvoicing::profile()])->render();
        $this->assertStringContainsString('01700000000', $html);
        $this->assertStringContainsString('BDT 17,500.00', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringStartsWith('%PDF', app(PersonalInvoicing::class)->pdf($inv->fresh())->output());
    }

    public function test_a_monthly_job_makes_its_invoice_two_days_before_pay_day_and_tells_me(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
        // EDGE: ৳5,000 a month, started on the 9th → paid every 9th for the month just worked.
        $this->post('/personal/recurring', ['personal_client_id' => $this->client->id, 'items' => [['description' => 'Monthly work', 'qty' => 1, 'rate' => 5000]],
            'start_on' => '2026-09-09', 'lead_days' => 2, 'auto_send' => false, 'active' => true])->assertSessionHasNoErrors();
        $job = PersonalRecurring::sole();
        $this->assertSame([9, '2026-11-09'], [$job->day, $job->next_on->toDateString()]);

        $invoicing = app(PersonalInvoicing::class);
        $this->assertSame(0, $invoicing->runRecurring(CarbonImmutable::parse('2026-11-06')));
        $this->assertSame(1, $invoicing->runRecurring(CarbonImmutable::parse('2026-11-07')));
        $this->assertSame(0, $invoicing->runRecurring(CarbonImmutable::parse('2026-11-08')));

        $inv = PersonalInvoice::sole();
        $this->assertSame([500000, '2026-11-09'], [$inv->total, $inv->due_on->toDateString()]);
        $this->assertSame('Monthly work (9 Oct – 8 Nov 2026)', $inv->items[0]['description']);
        $this->assertSame('2026-12-09', $job->fresh()->next_on->toDateString());
        $this->assertDatabaseHas('app_notifications', ['kind' => 'invoice', 'title' => '🧾 Rafiq Traders invoice ready — send it']);

        // A job that starts today is first paid a month from now.
        $this->post('/personal/recurring', ['personal_client_id' => $this->client->id, 'items' => [['description' => 'Retainer', 'qty' => 1, 'rate' => 100000]],
            'start_on' => '2026-10-25', 'lead_days' => 2, 'auto_send' => false, 'active' => true]);
        $this->assertSame('2026-11-25', PersonalRecurring::latest('id')->first()->next_on->toDateString());
        $this->assertSame('2027-02-28', PersonalInvoicing::nextOn(CarbonImmutable::parse('2027-01-31'), 31)->toDateString());
    }
}
