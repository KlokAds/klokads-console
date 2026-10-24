<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\Heartbeat;
use App\Services\PushNotifier;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phone reminders: turn on/off per device, pick times, and the cron-job.org ping that sends them. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'publicKey' => PushNotifier::keys()['publicKey'],
            'devices' => $request->user()->pushSubscriptions()->latest()->get()
                ->map(fn (PushSubscription $s) => $s->only('id', 'device', 'timezone', 'morning', 'morning_at', 'evening', 'evening_at', 'endpoint_hash') + [
                    'created_at' => $s->created_at->toIso8601String(),
                ]),
            'hasPin' => (bool) $request->user()->pin_hash,
            'app' => AppReleaseController::current(),
            'cron' => [
                'url' => route('reminders.ping', PushNotifier::cronToken()),
                'last_ping' => Setting::get('cron_last_ping'),
                'heartbeat_at' => Setting::get('heartbeat_at'),
                'woke_at' => Setting::get('heartbeat_woke_at'),
                'chain' => Setting::get('heartbeat_chain'),
            ],
        ]);
    }

    /** Save this device (or update its times). */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
            'device' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $sub = PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'device' => $data['device'] ?? null,
            'timezone' => $data['timezone'] ?? 'Asia/Singapore',
        ]);

        return response()->json(['id' => $sub->id, 'endpoint_hash' => $sub->endpoint_hash], 201);
    }

    public function update(Request $request, PushSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);

        $subscription->update($request->validate([
            'morning' => ['boolean'],
            'morning_at' => ['required', 'date_format:H:i'],
            'evening' => ['boolean'],
            'evening_at' => ['required', 'date_format:H:i'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
        ]));

        return back()->with('success', 'Reminder times saved.');
    }

    public function destroy(Request $request, PushSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        $subscription->delete();

        return back()->with('success', 'Reminders turned off on that device.');
    }

    /** Send the morning or night reminder to one device right now. */
    public function test(Request $request, PushSubscription $subscription, PushNotifier $push, Reminders $reminders): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        $local = CarbonImmutable::now($subscription->timezone);
        $which = $request->input('which') === 'evening' ? 'evening' : 'morning';

        $ok = $push->send($subscription, $which === 'evening' ? $reminders->evening($local) : $reminders->morning($local));

        return $ok
            ? back()->with('success', 'Sent — check your phone.')
            : back()->with('error', 'Could not reach that device. Turn reminders on again on it.');
    }

    /** cron-job.org calls this every few minutes; it sends whatever is due. */
    /**
     * The app's own wake-up call (and an optional outside trigger, e.g. cron-job.org):
     * run what is due, then leave the next wake-up.
     */
    public function ping(Request $request, string $token, Heartbeat $heartbeat): JsonResponse
    {
        abort_unless(hash_equals(PushNotifier::cronToken(), $token), 404);

        if ($request->boolean('wake')) {
            $heartbeat->woke();
        } else {
            Setting::put('cron_last_ping', now()->toIso8601String());
        }

        $result = $heartbeat->maybeRun() ?? ['skipped' => 'ran less than 4 minutes ago'];
        $heartbeat->scheduleNext();

        return response()->json(['ok' => true] + $result);
    }
}
