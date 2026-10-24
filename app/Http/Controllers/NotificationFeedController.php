<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;

/** The bell: the same notifications the phone shows (credits, debits, reminders, invoices). */
class NotificationFeedController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'unread' => AppNotification::whereNull('read_at')->count(),
            'items' => AppNotification::latest('id')->limit(40)->get(['id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at']),
        ]);
    }

    public function read(): JsonResponse
    {
        AppNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
