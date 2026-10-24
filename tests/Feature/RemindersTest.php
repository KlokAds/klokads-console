<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifier;
use App\Services\Reminders;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;
use Mockery\MockInterface;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $dbs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->dbs = Account::create(['name' => 'DBS', 'scope' => 'personal', 'kind' => 'bank', 'currency' => 'SGD', 'opening_balance' => 500000]);
    }

    private function device(array $attrs = []): PushSubscription
    {
        return PushSubscription::create($attrs + [
            'user_id' => $this->user->id, 'endpoint' => 'https://push.example/abc', 'endpoint_hash' => hash('sha256', 'https://push.example/abc'),
            'public_key' => 'pk', 'auth_token' => 'auth', 'timezone' => 'Asia/Singapore',
        ]);
    }

    public function test_morning_lists_bills_due_and_budgets_near_their_limit(): void
    {
        $local = CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Singapore');
        Bill::create(['name' => 'Room rent', 'account_id' => $this->dbs->id, 'category' => 'home', 'amount' => 180000, 'day' => 15]);
        Bill::create(['name' => 'Singtel', 'account_id' => $this->dbs->id, 'category' => 'bills', 'amount' => 4500, 'day' => 17]);
        Bill::create(['name' => 'Gym', 'account_id' => $this->dbs->id, 'category' => 'health', 'amount' => 9900, 'day' => 3]);
        Budget::create(['category' => 'food', 'currency' => 'SGD', 'amount' => 30000]);
        Ledger::save(['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 25500, 'category' => 'food', 'occurred_on' => '2026-10-10']);

        $m = app(Reminders::class)->morning($local);

        $this->assertSame('☀️ Good morning — 4 things today', $m['title']);
        $this->assertStringContainsString('⚠️ Overdue: Gym S$99.00', $m['body']);
        $this->assertStringContainsString('📌 Due today: Room rent S$1,800.00', $m['body']);
        $this->assertStringContainsString('🗓️ Coming up: Singtel S$45.00 (17th)', $m['body']);
        $this->assertStringContainsString('🟡 Food & groceries at 85% of budget', $m['body']);
    }

    public function test_evening_nudges_when_nothing_was_recorded_and_sums_when_it_was(): void
    {
        $local = CarbonImmutable::parse('2026-10-15 21:00', 'Asia/Singapore');
        $reminders = app(Reminders::class);

        $this->assertSame("🌙 Add today's spending", $reminders->evening($local)['title']);
        $this->assertStringContainsString('new=1', $reminders->evening($local)['url']);

        $bk = Account::create(['name' => 'bKash', 'scope' => 'personal', 'kind' => 'wallet', 'currency' => 'BDT']);
        Ledger::save(['kind' => 'expense', 'account_id' => $this->dbs->id, 'amount' => 1250, 'category' => 'food', 'occurred_on' => '2026-10-15']);
        Ledger::save(['kind' => 'expense', 'account_id' => $bk->id, 'amount' => 120000, 'category' => 'family', 'occurred_on' => '2026-10-15']);

        $this->assertSame('🌙 Today: S$12.50 · ৳1,200 spent', $reminders->evening($local)['title']);
    }

    public function test_each_reminder_goes_out_once_a_day_at_the_devices_local_time(): void
    {
        $this->device(['morning_at' => '09:00', 'evening_at' => '21:00']);
        $sent = [];
        $this->mock(PushNotifier::class, function (MockInterface $m) use (&$sent) {
            $m->shouldReceive('send')->andReturnUsing(function ($sub, $msg) use (&$sent) {
                $sent[] = $msg['tag'];

                return true;
            });
        });
        $reminders = app(Reminders::class);

        $reminders->run(CarbonImmutable::parse('2026-10-15 00:55 UTC')); // 08:55 SGT — too early
        $this->assertSame([], $sent);

        $reminders->run(CarbonImmutable::parse('2026-10-15 01:05 UTC')); // 09:05 SGT
        $reminders->run(CarbonImmutable::parse('2026-10-15 01:10 UTC'));
        $this->assertSame(['klok-morning'], $sent, 'once');

        $reminders->run(CarbonImmutable::parse('2026-10-15 13:02 UTC')); // 21:02 SGT
        $this->assertSame(['klok-morning', 'klok-evening'], $sent);

        $reminders->run(CarbonImmutable::parse('2026-10-16 01:01 UTC')); // next morning
        $this->assertSame(['klok-morning', 'klok-evening', 'klok-morning'], $sent);
    }

    public function test_generated_keys_can_sign_push_requests(): void
    {
        $keys = PushNotifier::keys();
        $vapid = VAPID::validate(['subject' => 'mailto:billing@klokads.com'] + $keys); // throws if the keys are malformed
        $headers = VAPID::getVapidHeaders('https://fcm.googleapis.com', $vapid['subject'], $vapid['publicKey'], $vapid['privateKey'], ContentEncoding::aes128gcm);

        $this->assertStringStartsWith('vapid t=', $headers['Authorization']);
        $this->assertSame($keys, PushNotifier::keys(), 'created once, then reused');
    }

    public function test_ping_needs_the_secret_token(): void
    {
        $this->get('/reminders/ping/wrong')->assertNotFound();
        $this->getJson('/reminders/ping/'.PushNotifier::cronToken())->assertOk()->assertJson(['ok' => true, 'reminders' => ['checked' => 0]]);
    }

    public function test_devices_subscribe_and_only_owners_can_change_them(): void
    {
        $this->actingAs($this->user)->postJson('/notifications/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au'],
            'device' => 'Android · Chrome (app)', 'timezone' => 'Asia/Dhaka',
        ])->assertCreated()->assertJsonPath('endpoint_hash', hash('sha256', 'https://fcm.googleapis.com/fcm/send/xyz'));

        $sub = PushSubscription::sole();
        $this->assertSame('Asia/Dhaka', $sub->timezone);
        $this->get('/notifications')->assertOk()->assertInertia(fn ($page) => $page->component('Notifications/Index')
            ->has('devices', 1)->missing('devices.0.endpoint')->where('publicKey', fn ($k) => strlen($k) > 40));

        $this->actingAs(User::factory()->create())->put("/notifications/{$sub->id}", ['morning_at' => '07:00', 'evening_at' => '22:00', 'timezone' => 'Asia/Dhaka'])->assertNotFound();
        $this->actingAs($this->user)->put("/notifications/{$sub->id}", ['morning' => true, 'morning_at' => '07:30', 'evening' => false, 'evening_at' => '22:00', 'timezone' => 'Asia/Dhaka'])->assertSessionHasNoErrors();
        $this->assertSame(['07:30', false], [$sub->fresh()->morning_at, $sub->fresh()->evening]);
    }
}
