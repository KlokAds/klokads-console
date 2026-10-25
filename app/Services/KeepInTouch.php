<?php

namespace App\Services;

use App\Models\Touch;
use Carbon\CarbonImmutable;

/**
 * Keep in touch with family. Each person due today gets a reminder at a time that suits them,
 * spread over the day (never all at once); "auto" uses the hour I usually talk to them.
 * A call in the phone's call log (I called, or I answered) counts as talking — no reminder.
 */
class KeepInTouch
{
    /** Start of each part of the day (local time). */
    public const WINDOWS = ['morning' => '10:00', 'afternoon' => '15:00', 'evening' => '20:00', 'night' => '21:30'];

    /** Reminders of the same part of the day are this far apart. */
    public const GAP_MINUTES = 40;

    /** Not answered: once more after this long, then tomorrow. */
    public const AGAIN_AFTER_MINUTES = 120;

    public const SUGGESTIONS = [
        ['name' => 'Ma', 'emoji' => '👩‍🦳', 'every_days' => 1, 'window' => 'evening'],
        ['name' => 'Baba', 'emoji' => '👴', 'every_days' => 2, 'window' => 'evening'],
        ['name' => 'Vai', 'emoji' => '👦', 'every_days' => 7, 'window' => 'auto'],
        ['name' => 'Shoshur', 'emoji' => '👨‍🦳', 'every_days' => 7, 'window' => 'afternoon'],
        ['name' => 'Shashuri', 'emoji' => '👵', 'every_days' => 7, 'window' => 'afternoon'],
        ['name' => 'Shali', 'emoji' => '👧', 'every_days' => 14, 'window' => 'auto'],
    ];

    public function __construct(private Notify $notify) {}

    /** A call with this number (I called or answered): we talked. */
    public function talked(?string $phone, ?CarbonImmutable $at = null): ?Touch
    {
        $digits = FollowUps::digits($phone);
        if (! $digits) {
            return null;
        }
        $touch = Touch::all()->first(fn (Touch $t) => FollowUps::digits($t->phone) === $digits);

        return $touch ? $this->mark($touch, $at ?? CarbonImmutable::now()) : null;
    }

    /** Talked (from the call log, or "Talked ✓" for a WhatsApp call or a visit). */
    public function mark(Touch $touch, ?CarbonImmutable $at = null): Touch
    {
        $at ??= CarbonImmutable::now();
        $hours = $touch->talk_hours ?? [];
        $hour = (string) $at->setTimezone(PersonalReminders::timezone())->hour;
        $hours[$hour] = ($hours[$hour] ?? 0) + 1;
        $touch->update([
            'last_talked_at' => $touch->last_talked_at && $touch->last_talked_at->gt($at) ? $touch->last_talked_at : $at,
            'talk_hours' => $hours, 'remind_at' => null, 'reminded' => 0,
        ]);

        return $touch;
    }

    /** The hour I most often talk to them, if the call log has shown it a few times. */
    public static function usualHour(Touch $touch): ?int
    {
        $hours = collect($touch->talk_hours ?? [])->filter(fn ($n, $h) => $h >= 8 && $h <= 22);

        return $hours->sum() >= 3 ? (int) $hours->sort()->keys()->last() : null;
    }

    public static function due(Touch $touch, CarbonImmutable $localToday): bool
    {
        return ! $touch->paused && (! $touch->last_talked_at
            || $touch->last_talked_at->setTimezone($localToday->timezone)->startOfDay()->addDays($touch->every_days)->lte($localToday));
    }

    /** Heartbeat: plan today's reminders (spread out), and send the ones whose time has come. */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone(PersonalReminders::timezone());
        $today = $local->startOfDay();

        // Plan each person due today once: their part of the day, then spaced out.
        $toPlan = Touch::orderBy('sort')->get()->filter(fn (Touch $t) => self::due($t, $today) && $t->planned_on?->toDateString() !== $today->toDateString());
        $taken = Touch::whereDate('planned_on', $today->toDateString())->whereNotNull('remind_at')->pluck('remind_at')
            ->map(fn ($at) => CarbonImmutable::parse($at)->setTimezone($local->timezone))->all();
        foreach ($toPlan->sortByDesc(fn ($t) => $t->last_talked_at ? $now->diffInHours($t->last_talked_at, true) : PHP_INT_MAX) as $t) {
            $hour = $t->window === 'auto' ? (self::usualHour($t) ?? 20) : null;
            // A part of the day, my own time ("21:00"), or the hour I usually call them.
            $slot = $hour !== null ? $today->setTime($hour, 0) : $today->setTimeFromTimeString(self::WINDOWS[$t->window] ?? (self::isTime($t->window) ? $t->window : self::WINDOWS['evening']));
            if ($slot->lt($local)) {
                $slot = $local->addMinutes(5);
            }
            while (collect($taken)->contains(fn ($at) => abs($at->diffInMinutes($slot, true)) < self::GAP_MINUTES)) {
                $slot = $slot->addMinutes(self::GAP_MINUTES);
            }
            $taken[] = $slot;
            $t->update(['planned_on' => $today->toDateString(), 'remind_at' => $slot->utc(), 'reminded' => 0]);
        }

        $sent = 0;
        foreach (Touch::where('paused', false)->whereNotNull('remind_at')->where('remind_at', '<=', $now->utc())->get() as $t) {
            if (! self::due($t, $today)) {
                $t->update(['remind_at' => null]);

                continue;
            }
            $since = $t->last_talked_at ? (int) $t->last_talked_at->setTimezone($local->timezone)->startOfDay()->diffInDays($today) : null;
            $usual = self::usualHour($t);
            $body = implode(' · ', array_filter([
                $since === null ? 'Not on record yet' : ($since <= 1 ? 'Last talked yesterday' : "{$since} days since you talked"),
                $usual !== null ? 'you usually call around '.($usual % 12 ?: 12).($usual < 12 ? ' am' : ' pm') : null,
                $t->phone ? "📞 {$t->phone}" : null,
            ]));
            $this->notify->send('reminder', "📞 Call {$t->name} {$t->emoji}", $body, route('personal.touch.index', ['k' => $t->id]), 'klok-touch');
            $t->update(['reminded' => $t->reminded + 1, 'remind_at' => $t->reminded + 1 < 2 ? $now->utc()->addMinutes(self::AGAIN_AFTER_MINUTES) : null]);
            $sent++;
        }

        return $sent;
    }

    public static function isTime(?string $window): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $window);
    }

    public function later(Touch $touch, int $minutes = 60): void
    {
        $touch->update(['remind_at' => now()->addMinutes($minutes), 'reminded' => 0]);
    }
}
