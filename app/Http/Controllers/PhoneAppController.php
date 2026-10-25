<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PersonalAccess;
use App\Models\AppNotification;
use App\Models\FollowUp;
use App\Models\Setting;
use App\Services\AutoEntries;
use App\Services\FollowUps;
use App\Services\Notify;
use App\Services\PersonalReminders;
use App\Support\InboxRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The MH-Bot Android app. It is paired once from inside the unlocked personal side, then
 * forwards bank / wallet SMS and app notifications, and shows the notification feed
 * (automatic credits/debits, reminders) as phone notifications.
 */
class PhoneAppController extends Controller
{
    /** Called by the app's built-in browser while I'm signed in and unlocked. */
    public function pair(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_owner, 403);
        Setting::put('app_paired_at', now()->toIso8601String());

        return response()->json([
            'token' => AutoEntries::token(),
            'feed' => route('app.feed', ['token' => AutoEntries::token()]),
            'inbox' => route('inbox.sms', ['token' => AutoEntries::token()]),
            'config' => route('app.config', ['token' => AutoEntries::token()]),
            'followups' => route('app.followups', ['token' => AutoEntries::token()]),
            'last_id' => (int) AppNotification::max('id'),
        ]);
    }

    /** The senders, wallet apps and ignore words — the phone keeps a copy and can change them. */
    public function config(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);

        if ($request->isMethod('put')) {
            $data = $request->validate([
                'senders' => ['sometimes', 'array', 'max:200'],
                'senders.*.name' => ['required', 'string', 'max:40'],
                'senders.*.type' => ['nullable', 'in:sms,app'],
                'senders.*.on' => ['boolean'],
                'ignore' => ['sometimes', 'array', 'max:200'],
                'ignore.*' => ['string', 'max:60'],
                'whitelist' => ['sometimes', 'boolean'],
            ]);

            return response()->json(InboxRules::save($data));
        }

        return response()->json(InboxRules::all());
    }

    /**
     * A test notification: from here through the feed to the phone (and to browsers), so one tap
     * shows whether the whole chain works. Called by the app (token) or the panel (signed in).
     */
    public function test(Request $request, Notify $notify, ?string $token = null): JsonResponse
    {
        if ($token !== null) {
            abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        }
        $note = $notify->send('info', '🔔 MH-Bot test', 'If this is on your phone, notifications work. Sent '.now()->setTimezone(PersonalReminders::timezone())->format('g:i a').'.', route('personal.dashboard'), 'klok-test');

        return response()->json(['ok' => true, 'id' => $note->id]);
    }

    /** Chat notifications and calls from the phone: new, seen, called back. Sent in batches. */
    public function followUps(Request $request, FollowUps $followUps, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        $data = $request->validate([
            'events' => ['required', 'array', 'max:100'],
            'events.*.action' => ['required', 'in:new,seen,called_back'],
            'events.*.source' => ['required', 'in:'.implode(',', array_keys(FollowUps::SOURCES))],
            'events.*.kind' => ['nullable', 'in:message,missed_call'],
            'events.*.person' => ['nullable', 'string', 'max:190'],
            'events.*.phone' => ['nullable', 'string', 'max:30'],
            'events.*.text' => ['nullable', 'string', 'max:2000'],
            'events.*.at' => ['nullable', 'integer'],
            'events.*.duration' => ['nullable', 'integer', 'min:0'],
        ]);
        foreach ($data['events'] as $event) {
            $followUps->record($event);
        }

        return response()->json(['ok' => true, 'open' => FollowUp::open()->count()]);
    }

    /** The follow-ups list for the app's own screen. */
    public function followUpList(FollowUps $followUps, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);

        return response()->json([
            'open' => FollowUp::open()->latest('last_at')->limit(100)->get()->map(fn ($f) => $followUps->row($f)),
            'closed' => FollowUp::where(fn ($q) => $q->whereNotNull('seen_at')->orWhereNotNull('done_at'))->latest('updated_at')->limit(30)->get()->map(fn ($f) => $followUps->row($f)),
            'sources' => FollowUps::SOURCES,
        ]);
    }

    /** Done / later / note — from the app's screen or the buttons on the phone notification. */
    public function followUpAction(Request $request, FollowUps $followUps, string $token, FollowUp $followUp, string $action): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        match ($action) {
            'done' => $followUps->done($followUp),
            'later' => $followUps->later($followUp, (int) ($request->validate(['minutes' => ['nullable', 'integer', 'in:30,60,180,1440']])['minutes'] ?? 60)),
            'note' => $followUp->update($request->validate(['note' => ['nullable', 'string', 'max:500']])),
            default => abort(404),
        };

        return response()->json(['ok' => true, 'item' => $followUps->row($followUp->fresh())]);
    }

    /**
     * Inside the app, its own lock (fingerprint / app PIN) is the lock: once it passes, the app
     * calls this with its private key and the personal side opens without the web PIN.
     * Only the paired app has the key; the browser keeps asking for the web PIN.
     */
    public function unlock(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        abort_unless($request->user()?->is_owner, 403);
        $request->session()->put('personal_unlocked_until', now()->addMinutes(PersonalAccess::IDLE_MINUTES)->timestamp);

        return response()->json(['ok' => true, 'to' => $request->session()->pull('personal_intended', route('personal.dashboard'))]);
    }

    /** "✓ Seen" tapped on the phone: read in the bell too. */
    public function read(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        AppNotification::whereKey($request->integer('id'))->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** New notifications since the last one the phone showed. */
    public function feed(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        // The phone asks every 15 minutes: that is how the panel knows the app is alive.
        if (Setting::get('app_seen_at') < now()->subMinutes(5)->toIso8601String()) {
            Setting::put('app_seen_at', now()->toIso8601String());
        }
        $after = (int) $request->integer('after');

        return response()->json([
            'items' => AppNotification::where('id', '>', $after)->orderBy('id')->limit(30)->get(['id', 'kind', 'title', 'body', 'url', 'created_at']),
            'last_id' => (int) AppNotification::max('id'),
        ]);
    }
}
