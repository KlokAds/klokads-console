<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * State and helpers for the one-time web installer (/install).
 */
class Installer
{
    public static function markerPath(): string
    {
        return storage_path('app/private/installed.json');
    }

    public static function codePath(): string
    {
        return storage_path('app/private/install-code.txt');
    }

    public static function isInstalled(): bool
    {
        $flag = config('klok.installed');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return file_exists(self::markerPath());
    }

    public static function markInstalled(): void
    {
        @mkdir(dirname(self::markerPath()), 0755, true);
        file_put_contents(self::markerPath(), json_encode(['installed_at' => now()->toIso8601String()]));
        @unlink(self::codePath());
    }

    /** The one-time setup code, created on first use and kept outside the web root. */
    public static function setupCode(): string
    {
        if (! file_exists(self::codePath())) {
            @mkdir(dirname(self::codePath()), 0755, true);
            file_put_contents(self::codePath(), strtoupper(Str::random(4).'-'.Str::random(4)));
            @chmod(self::codePath(), 0600);
        }

        return trim((string) file_get_contents(self::codePath()));
    }

    public static function checkCode(?string $code): bool
    {
        return is_string($code) && hash_equals(self::setupCode(), strtoupper(trim($code)));
    }

    /**
     * @return array<int, array{label: string, ok: bool}>
     */
    public static function requirements(): array
    {
        return [
            ['label' => 'PHP 8.3 or newer (running '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')],
            ...collect(['pdo_mysql', 'mbstring', 'openssl', 'curl', 'bcmath'])
                ->map(fn ($ext) => ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext)])
                ->all(),
            ['label' => '.env file is writable', 'ok' => is_writable(base_path('.env'))],
            ['label' => 'storage/ is writable', 'ok' => is_writable(storage_path())],
            ['label' => 'bootstrap/cache/ is writable', 'ok' => is_writable(base_path('bootstrap/cache'))],
        ];
    }

    /** Whether the configured database answers and has our tables. */
    public static function databaseReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return Schema::hasTable('migrations') && Schema::hasTable('users');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $db
     * @return string|null error message, or null when the connection works
     */
    public static function testConnection(array $db): ?string
    {
        try {
            new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}",
                $db['username'],
                (string) ($db['password'] ?? ''),
                [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Save MySQL credentials to .env, switch the running app to them and create the tables.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string}  $db
     */
    public static function configureDatabase(array $db): void
    {
        self::writeEnv(base_path('.env'), [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
        ]);

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', array_merge(config('database.connections.mysql'), [
            'host' => $db['host'],
            'port' => $db['port'],
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => (string) ($db['password'] ?? ''),
        ]));
        DB::purge('mysql');

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Set keys in a .env file, keeping every other line as it is.
     *
     * @param  array<string, string>  $values
     */
    public static function writeEnv(string $path, array $values): void
    {
        $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote($value);
            $found = false;

            foreach ($lines as $i => $existing) {
                if (preg_match('/^#?\s*'.preg_quote($key, '/').'=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $lines[] = $line;
            }
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);
    }

    /** Quote a value so dotenv reads it back exactly (no variable expansion). */
    public static function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException('Values cannot contain line breaks.');
        }

        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (str_contains($value, '${')) {
            throw new InvalidArgumentException('Values cannot contain both a single quote and "${".');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
