<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\AutoEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Klok Android app. It is paired once from inside the unlocked personal side, then
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
            'last_id' => (int) AppNotification::max('id'),
        ]);
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
