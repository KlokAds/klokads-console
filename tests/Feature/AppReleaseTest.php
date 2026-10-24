<?php

namespace Tests\Feature;

use App\Http\Controllers\AppReleaseController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_github_build_hands_over_the_apk_and_the_app_finds_and_downloads_it(): void
    {
        Storage::fake('local');
        $this->get('/app/get')->assertNotFound();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('app', null));
        $url = AppReleaseController::newUploadUrl();
        $apk = UploadedFile::fake()->create('klok.apk', 200, 'application/vnd.android.package-archive');

        $this->post(str_replace(config('app.url'), '', $url).'x', ['version' => 2, 'name' => '1.0.1', 'apk' => $apk])->assertNotFound();
        $this->postJson($url, ['version' => 2, 'name' => '1.0.1', 'apk' => $apk])->assertOk();

        // The sign-in page offers it; each tap gets a fresh link.
        $this->get('/login')->assertInertia(fn ($page) => $page->where('app.version', '1.0.1'));
        $this->get('/app/get')->assertRedirectContains('/app/download?expires=');

        $this->getJson('/app/latest')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_owner' => true]));
        $latest = $this->getJson('/app/latest')->assertOk()->assertJsonPath('version', 2)->json();

        $this->get($latest['url'])->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->get('/app/download')->assertForbidden(); // only through the signed link

        $this->actingAs(User::factory()->create(['is_owner' => false]));
        $this->getJson('/app/latest')->assertForbidden();
    }
}
