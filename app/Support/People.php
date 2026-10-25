<?php

namespace App\Support;

use App\Models\Setting;

/** The people I spend on (family), each with an emoji. My own list; these to start. */
class People
{
    public const DEFAULT = [
        ['name' => 'Ma', 'emoji' => '👩‍🦳'], ['name' => 'Baba', 'emoji' => '👴'], ['name' => 'Bou', 'emoji' => '👩'],
        ['name' => 'Vai', 'emoji' => '👦'], ['name' => 'Shoshur', 'emoji' => '👨‍🦳'], ['name' => 'Shashuri', 'emoji' => '👵'],
        ['name' => 'Shali', 'emoji' => '👧'],
    ];

    /** @return array<int, array{name: string, emoji: string}> */
    public static function all(): array
    {
        $saved = json_decode((string) Setting::get('people'), true);

        return is_array($saved) ? $saved : self::DEFAULT;
    }

    /** @param  array<int, array{name?: string, emoji?: string}>  $people */
    public static function save(array $people): array
    {
        $seen = [];
        $clean = [];
        foreach ($people as $p) {
            $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 40);
            if ($name === '' || isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $seen[mb_strtolower($name)] = true;
            $clean[] = ['name' => $name, 'emoji' => mb_substr(trim((string) ($p['emoji'] ?? '')), 0, 16) ?: '🙂'];
        }
        Setting::put('people', json_encode($clean));

        return $clean;
    }
}
