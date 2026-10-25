<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\FollowUp;
use App\Models\Loan;
use App\Models\PersonalClient;
use App\Models\PersonalRecurring;
use App\Models\User;
use App\Services\AutoEntries;
use App\Services\FollowUps;
use App\Services\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $this->url = '/app/followups/'.AutoEntries::token();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
    }

    private function event(array $e): void
    {
        $this->postJson($this->url, ['events' => [$e + ['at' => now()->getTimestampMs()]]])->assertOk();
    }

    public function test_an_unseen_whatsapp_message_reminds_after_an_hour_with_what_it_is_about(): void
    {
        $client = PersonalClient::create(['name' => 'Zabed Vai', 'phone' => '01711000000']);
        PersonalRecurring::create(['personal_client_id' => $client->id, 'items' => [['description' => 'Monthly work', 'qty' => 1, 'rate' => 500000]],
            'day' => 5, 'start_on' => '2026-10-05', 'lead_days' => 2, 'due_days' => 0, 'auto_send' => false, 'active' => true, 'next_on' => '2026-11-05']);

        $this->event(['action' => 'new', 'source' => 'whatsapp', 'kind' => 'message', 'person' => 'Zabed Vai', 'text' => 'Bhai invoice ta pathan']);
        $this->event(['action' => 'new', 'source' => 'whatsapp', 'kind' => 'message', 'person' => 'Zabed Vai', 'text' => 'Ar meeting kobe?']);
        $this->assertSame(2, FollowUp::sole()->count);

        // Once right away (only for the first message), then after an hour, then every 6 hours.
        $this->assertSame('💬 WhatsApp: Zabed Vai', AppNotification::sole()->title);
        $run = app(FollowUps::class);
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 10:30')));
        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 11:01')));
        $this->assertSame(0, $run->run(CarbonImmutable::parse('2026-10-25 16:30')), 'not again before 6 hours');
        $this->assertSame(1, $run->run(CarbonImmutable::parse('2026-10-25 17:02')));

        $note = AppNotification::latest('id')->first();
        $this->assertStringStartsWith('💬 Unseen on WhatsApp: Zabed Vai', $note->title);
        $this->assertStringContainsString('Ar meeting kobe?', $note->body);
        $this->assertStringContainsString('Job ৳5,000.00/month · pay day 5 Nov', $note->body);
        $this->assertStringContainsString('f='.FollowUp::sole()->id, $note->url);
    }

    public function test_opening_the_chat_or_calling_back_closes_it_so_no_reminder_comes(): void
    {
        $this->event(['action' => 'new', 'source' => 'whatsapp', 'person' => 'Rahim', 'text' => 'Hi']);
        $this->event(['action' => 'seen', 'source' => 'whatsapp', 'person' => 'Rahim']);

        Loan::create(['direction' => 'lent', 'person' => 'Karim', 'phone' => '+8801811000000', 'currency' => 'BDT', 'principal' => 300000, 'started_on' => '2026-10-01']);
        $this->event(['action' => 'new', 'source' => 'call', 'kind' => 'missed_call', 'person' => 'Karim', 'phone' => '01811000000']);
        $this->assertStringContainsString('Owes you ৳3,000.00', app(FollowUps::class)->context(FollowUp::latest('id')->first()));
        $this->event(['action' => 'called_back', 'source' => 'call', 'phone' => '+880 1811-000000']);

        $this->assertSame(0, FollowUp::open()->count());
        $this->assertSame(0, app(FollowUps::class)->run(CarbonImmutable::parse('2026-10-25 12:00')));
    }

    public function test_the_app_lists_follow_ups_and_marks_them_done_or_later(): void
    {
        $this->event(['action' => 'new', 'source' => 'imo', 'person' => 'Ammu', 'text' => 'Call me']);
        $f = FollowUp::sole();

        $this->getJson($this->url)->assertOk()->assertJsonPath('open.0.person', 'Ammu')->assertJsonPath('sources.imo', 'IMO');
        $this->postJson("{$this->url}/{$f->id}/later", ['minutes' => 180])->assertOk();
        $this->assertSame('2026-10-25 13:00', $f->fresh()->remind_at->format('Y-m-d H:i'));
        $this->postJson("{$this->url}/{$f->id}/note", ['note' => 'About Eid shopping'])->assertJsonPath('item.note', 'About Eid shopping');
        $this->postJson("{$this->url}/{$f->id}/done")->assertOk();
        $this->assertSame(0, FollowUp::open()->count());

        $this->get('/personal/followups')->assertOk()->assertInertia(fn ($page) => $page->component('Personal/FollowUps')->has('closed', 1));
        $this->getJson('/app/followups/wrong')->assertNotFound();
    }

    public function test_one_notification_per_chat_showing_the_latest_message(): void
    {
        $this->event(['action' => 'new', 'source' => 'whatsapp', 'kind' => 'message', 'person' => 'Rahim', 'text' => 'Bhai, ad ta kobe on hobe?']);
        $this->event(['action' => 'new', 'source' => 'whatsapp', 'kind' => 'message', 'person' => 'Rahim', 'text' => 'Budget 50 dollar thik ache']);
        $this->event(['action' => 'new', 'source' => 'whatsapp', 'kind' => 'message', 'person' => 'Karim', 'text' => 'Salam']);

        $this->assertSame(2, AppNotification::count(), 'one per chat, not one per message');
        $rahim = AppNotification::where('title', 'like', '%Rahim%')->sole();
        $this->assertStringContainsString('Budget 50 dollar thik ache', $rahim->body);
        $this->assertStringContainsString('(2 messages)', $rahim->body);
        $this->assertMatchesRegularExpression('/[?&]c=[a-f0-9]{10}/', $rahim->url);
    }

    public function test_every_notification_opens_a_page_business_on_the_business_side(): void
    {
        $notify = app(Notify::class);

        $this->assertSame(route('ad-money.index'), $notify->send('billing', 'Meta spend')->url);
        $this->assertSame(route('personal.activity'), $notify->send('debit', 'bKash −500')->url);
        $this->assertSame(route('notifications.index'), $notify->send('reminder', 'Spent anything?')->url);
        $this->assertSame(route('personal.loans.index'), $notify->send('reminder', 'Rahim owes', null, route('personal.loans.index'))->url);
    }

    public function test_messenger_chats_of_another_logged_in_account_are_ignored(): void
    {
        $this->event(['action' => 'new', 'source' => 'messenger', 'kind' => 'message', 'person' => '[Klok Ads] Rahim', 'text' => 'Hi']);
        $this->event(['action' => 'new', 'source' => 'messenger', 'kind' => 'message', 'person' => 'Rahim', 'text' => '[Klok Ads] Hi']);

        $this->assertSame(0, FollowUp::count());
        $this->assertSame(0, AppNotification::count());
    }
}
