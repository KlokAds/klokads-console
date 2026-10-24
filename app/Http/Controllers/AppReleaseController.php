<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Klok Android app's own updates. The pages inside it come from this site and are always
 * new; only the phone side (SMS, notifications, lock) needs a new APK. GitHub builds it and
 * sends it here; the app asks whether there is a newer one and installs it itself.
 */
class AppReleaseController extends Controller
{
    public const FILE = 'app/klok.apk';

    /** A new upload URL for the GitHub build (shown once; only its hash is kept). */
    public static function newUploadUrl(): string
    {
        $token = Str::random(48);
        Setting::put('app_release_token', Hash::make($token));

        return route('hooks.app-release', ['token' => $token]);
    }

    public function upload(Request $request, string $token): JsonResponse
    {
        $hash = (string) Setting::get('app_release_token');
        abort_unless($hash !== '' && Hash::check($token, $hash), 404);

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:65000'],
            'name' => ['required', 'string', 'max:30'],
            'apk' => ['required', 'file', 'max:204800'],
        ]);

        Storage::disk('local')->putFileAs('app', $data['apk'], basename(self::FILE));
        Setting::put('app_version', (string) $data['version']);
        Setting::put('app_version_name', $data['name']);
        Setting::put('app_released_at', now()->toIso8601String());

        return response()->json(['ok' => true]);
    }

    /** The app (signed in as me) asks: is there a newer APK? */
    public function latest(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_owner, 403);

        return response()->json(self::current());
    }

    /** @return array{version: int, name: ?string, url: ?string, released_at: ?string} */
    public static function current(): array
    {
        $version = (int) Setting::get('app_version', 0);
        $ready = $version > 0 && Storage::disk('local')->exists(self::FILE);

        return [
            'version' => $version,
            'name' => Setting::get('app_version_name'),
            // Works for half an hour, also in the phone's browser (not signed in there).
            'url' => $ready ? URL::temporarySignedRoute('app.download', now()->addMinutes(30)) : null,
            'released_at' => Setting::get('app_released_at'),
        ];
    }

    public function download(): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists(self::FILE), 404);

        return Storage::disk('local')->download(self::FILE, 'klok.apk', ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
