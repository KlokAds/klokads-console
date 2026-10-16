<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Installer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Let the marker file decide, as on a fresh server.
        config(['klok.installed' => null]);
        @unlink(Installer::markerPath());
        @unlink(Installer::codePath());
    }

    protected function tearDown(): void
    {
        @unlink(Installer::markerPath());
        @unlink(Installer::codePath());

        parent::tearDown();
    }

    public function test_every_page_leads_to_the_installer_until_installed(): void
    {
        $this->get('/dashboard')->assertRedirect('/install');
        $this->get('/login')->assertRedirect('/install');

        $this->get('/install')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Install/Index')->where('step', 'code'));
    }

    public function test_the_setup_code_gates_every_step(): void
    {
        $this->post('/install/database', ['host' => '127.0.0.1'])->assertForbidden();
        $this->post('/install/admin', [])->assertForbidden();

        $this->from('/install')->post('/install/code', ['code' => 'WRONG-CODE'])->assertSessionHasErrors('code');

        $this->post('/install/code', ['code' => strtolower(Installer::setupCode())])
            ->assertRedirect('/install')
            ->assertSessionHas('install_verified', true);
    }

    public function test_full_setup_stores_a_hashed_password_and_closes_the_installer(): void
    {
        $this->withSession(['install_verified' => true]);

        // The in-memory test database is already migrated, so the wizard is on the admin step.
        $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('step', 'admin'));

        $this->post('/install/admin', [
            'name' => 'Owner',
            'email' => 'owner@example.sg',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect('/install');

        $user = User::firstWhere('email', 'owner@example.sg');
        $this->assertNotSame('a-long-password', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('a-long-password', $user->password));
        $this->assertNotNull($user->email_verified_at);

        $this->post('/install/business', ['name' => 'Klok Ads', 'currency' => 'SGD'])->assertRedirect('/dashboard');

        $this->assertTrue(Installer::isInstalled());
        $this->assertFileDoesNotExist(Installer::codePath());
        $this->assertSame('sgd', Setting::get('currency'));
        $this->assertAuthenticatedAs($user);

        $this->get('/install')->assertRedirect('/login');
        $this->post('/install/code', ['code' => 'ANY'])->assertRedirect('/login');
    }

    public function test_env_values_round_trip_exactly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_NAME=Test\n# DB_PASSWORD=\nDB_HOST=old\n");

        $tricky = [
            'DB_PASSWORD' => 'p@ss#word $HOME ${X} "q"',
            'DB_USERNAME' => "o'neil\\x",
            'DB_HOST' => '127.0.0.1',
        ];
        Installer::writeEnv($path, $tricky);

        $parsed = Dotenv::parse(file_get_contents($path));
        $this->assertSame('Test', $parsed['APP_NAME']);
        foreach ($tricky as $key => $value) {
            $this->assertSame($value, $parsed[$key], $key);
        }
        $this->assertSame(1, substr_count(file_get_contents($path), 'DB_PASSWORD='));

        unlink($path);
    }

    public function test_env_values_with_line_breaks_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Installer::quote("line1\nline2");
    }
}
