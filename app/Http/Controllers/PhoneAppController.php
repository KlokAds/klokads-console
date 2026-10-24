<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\AutoEntries;
use App\Services\Notify;
use App\Services\PersonalReminders;
use App\Support\InboxRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The MH-Pilot Android app. It is paired once from inside the unlocked personal side, then
 * forwards bank / wallet SMS and app notifications, and shows the notification feed
 * (automatic credits/debits, reminders) as phone notifications.
 */
class PhoneAppController extends Controller
{
    /** Called by the app's built-in browser while I'm signed in and unlocked. */
    public function pair(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_owner, 403);

        return response()->json([
            'token' => AutoEntries::token(),
            'feed' => route('app.feed', ['token' => AutoEntries::token()]),
            'inbox' => route('inbox.sms', ['token' => AutoEntries::token()]),
            'config' => route('app.config', ['token' => AutoEntries::token()]),
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
        $note = $notify->send('info', '🔔 MH-Pilot test', 'If this is on your phone, notifications work. Sent '.now()->setTimezone(PersonalReminders::timezone())->format('g:i a').'.', route('personal.dashboard'), 'klok-test');

        return response()->json(['ok' => true, 'id' => $note->id]);
    }

    /** New notifications since the last one the phone showed. */
    public function feed(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);
        $after = (int) $request->integer('after');

        return response()->json([
            'items' => AppNotification::where('id', '>', $after)->orderBy('id')->limit(30)->get(['id', 'kind', 'title', 'body', 'url', 'created_at']),
            'last_id' => (int) AppNotification::max('id'),
        ]);
    }
}
