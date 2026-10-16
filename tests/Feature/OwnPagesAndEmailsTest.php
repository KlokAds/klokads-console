<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceMailer;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class OwnPagesAndEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $attributes = []): Invoice
    {
        $client = Client::create(['name' => 'Jane', 'company' => 'Bakery', 'email' => 'jane@example.sg']);

        return Invoice::create($attributes + [
            'client_id' => $client->id, 'stripe_invoice_id' => 'in_'.uniqid(), 'number' => 'KA-7', 'currency' => 'sgd',
            'status' => 'open', 'total' => 51679, 'subtotal' => 51679, 'amount_remaining' => 51679, 'service' => 'meta_ads',
            'collection_method' => 'send_invoice', 'issued_at' => now(), 'due_at' => now()->addWeek(),
            'lines' => [['description' => 'Meta Ads budget', 'amount' => 50000, 'quantity' => 1]],
        ]);
    }

    private function smtp(): void
    {
        Setting::put('smtp_host', 'smtp.example.com');
        Setting::put('smtp_username', 'billing@klokads.com');
        Setting::put('smtp_password', 'secret');
    }

    public function test_public_page_shows_our_invoice_with_the_card_box_secret(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');
        Setting::put('stripe_publishable', 'pk_test_abc123');
        $invoice = $this->invoice();

        $this->mock(StripeService::class, function (MockInterface $m) {
            $m->shouldReceive('paymentInfo')->andReturn(['paid_with' => null, 'charge_id' => null]);
            $m->shouldReceive('paymentSecret')->once()->andReturn('pi_123_secret_456');
        });

        $this->get('/i/'.$invoice->publicToken())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Invoice')
            ->where('doc.number', 'KA-7')
            ->where('payment.client_secret', 'pi_123_secret_456')
            ->where('payment.publishable_key', 'pk_test_abc123')
            ->missing('doc.client.id'));
    }

    public function test_public_links_are_secret_and_work_in_any_mode(): void
    {
        $invoice = $this->invoice(['livemode' => false]);
        Setting::put('stripe_secret', 'sk_live_abc123'); // console now in live mode

        $this->get('/i/'.str_repeat('x', 40))->assertNotFound();
        $this->get('/i/short')->assertNotFound();
        $this->get('/i/'.$invoice->publicToken().'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_email_invoice_sends_our_email_with_the_pdf_attached(): void
    {
        Mail::fake();
        $this->smtp();
        $invoice = $this->invoice();

        $this->actingAs(User::factory()->create())->from('/invoices')->post("/invoices/{$invoice->id}/send")->assertSessionHas('success');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice) {
            $attachment = $mail->attachments()[0];
            $this->assertSame('Invoice-KA-7.pdf', $attachment->as);
            $this->assertSame('application/pdf', $attachment->mime);

            return $mail->hasTo('jane@example.sg') && $mail->kind === 'invoice'
                && str_contains($mail->render(), $invoice->fresh()->publicUrl());
        });
        $this->assertNotNull($invoice->fresh()->invoice_sent_at);
    }

    public function test_without_smtp_the_owner_gets_a_clear_message(): void
    {
        $invoice = $this->invoice();

        $this->actingAs(User::factory()->create())->from('/invoices')->post("/invoices/{$invoice->id}/send")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Settings → Email'));
    }

    public function test_receipt_goes_out_once_when_an_invoice_becomes_paid_but_not_for_old_history(): void
    {
        Mail::fake();
        $this->smtp();
        $mailer = app(InvoiceMailer::class);

        $before = $this->invoice();
        $after = tap($before->replicate()->fill(['status' => 'paid', 'paid_at' => now()]))->forceFill(['id' => $before->id]);
        $before->update(['status' => 'paid', 'paid_at' => now()]);
        $mailer->afterSync($this->invoice(['stripe_invoice_id' => 'in_x', 'status' => 'open']), $before->fresh());
        Mail::assertSent(InvoiceMail::class, fn ($m) => $m->kind === 'receipt');

        Mail::fake();
        $mailer->afterSync(null, $this->invoice(['stripe_invoice_id' => 'in_old', 'status' => 'paid', 'paid_at' => now()->subMonths(3)]));
        Mail::assertNothingSent();

        Mail::fake();
        $mailer->afterSync($before->fresh(), $before->fresh()); // already sent
        Mail::assertNothingSent();
    }

    public function test_smtp_password_is_encrypted_and_test_email_works(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'owner@klokads.com']);

        $this->actingAs($user)->post('/settings/email', [
            'host' => 'smtp.hostinger.com', 'port' => 465, 'username' => 'billing@klokads.com', 'password' => 'mailbox-pass',
            'from_address' => 'billing@klokads.com', 'from_name' => 'Klok Ads', 'auto_receipts' => true, 'auto_invoices' => true,
        ])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('mailbox-pass', (string) \DB::table('settings')->where('key', 'smtp_password')->value('value'));
        $this->actingAs($user)->post('/settings/email/test')->assertSessionHas('success');
    }

    public function test_publishable_key_must_match_the_secret_key_mode(): void
    {
        Setting::put('stripe_secret', 'sk_test_abc123');

        $this->actingAs(User::factory()->create())->from('/settings')->post('/settings/stripe', ['stripe_publishable' => 'pk_live_abc'])
            ->assertSessionHasErrors('stripe_publishable');

        $this->actingAs(User::factory()->create())->post('/settings/stripe', ['stripe_publishable' => 'pk_test_abc'])->assertSessionHasNoErrors();
        $this->assertSame('pk_test_abc', Setting::get('stripe_publishable'));
    }
}
