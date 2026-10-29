<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Touch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The app's built-in clock — reminders, Meta billing, alert emails, monthly invoices.
 *
 * It keeps itself going: after each run it leaves a small wake-up on the server
 * ("call me again in 5 minutes"), so jobs run on time even when nobody opens the app.
 * If that chain ever breaks (server restart), the next page visit, SMS or webhook
 * runs the jobs and starts a new chain — so nothing is missed for long.
 */
class Heartbeat
{
    /** Jobs run at most this often. */
    public const EVERY_SECONDS = 240;

    /** The wake-up calls back after this long. */
    public const WAKE_AFTER_SECONDS = 300;

    /** Run if the last run was long enough ago. */
    public function maybeRun(): ?array
    {
        if (! Cache::add('heartbeat', 1, self::EVERY_SECONDS)) {
            return null;
        }

        return $this->run();
    }

    /**
     * Just the reminders set for a time (tasks, follow-ups, family calls) — cheap, so the phone's
     * check runs them on the spot instead of waiting up to 5 minutes for the next full run.
     */
    public function reminders(): void
    {
        if (! Cache::add('heartbeat-reminders', 1, 20)) {
            return;
        }
        foreach ([Tasks::class, FollowUps::class, KeepInTouch::class] as $job) {
            try {
                app($job)->run();
            } catch (Throwable $e) {
                Log::warning("Heartbeat {$job} failed: ".$e->getMessage());
            }
        }
    }

    /** When the next timed reminder is due (the phone sets an exact alarm for it). */
    public static function nextReminderAt(): ?int
    {
        $now = now();
        $next = collect([
            Touch::where('paused', false)->where('remind_at', '>', $now)->min('remind_at'),
            Task::whereNull('done_at')->where('remind_at', '>', $now)->where('reminded', '<', Tasks::MAX_REMINDERS)->min('remind_at'),
            FollowUp::open()->where('remind_at', '>', $now)->where('reminded', '<', FollowUps::MAX_REMINDERS)->min('remind_at'),
        ])->filter()->map(fn ($at) => CarbonImmutable::parse($at, 'UTC')->timestamp)->min();

        return $next ? (int) $next : null;
    }

    public function run(): array
    {
        @set_time_limit(120);
        $result = [];

        // Each job on its own: one failing never stops the others.
        foreach ([
            'reminders' => fn () => app(Reminders::class)->run(),
            'billing' => fn () => app(SpendBilling::class)->runAll(),
            'ad_history' => fn () => app(SpendBilling::class)->importAllHistory(),
            'stripe_payouts' => fn () => app(StripeService::class)->syncRecentPayouts(),
            'airwallex' => fn () => app(Airwallex::class)->run(),
            'ad_statements' => fn () => app(AdStatements::class)->draftMonthly(),
            'ad_performance' => fn () => app(AdPerformance::class)->run(),
            'personal_ads' => fn () => app(PersonalAds::class)->run(),
            'bank_balances' => fn () => app(AutoEntries::class)->reconcile(),
            'ad_advice' => fn () => app(AdAdvice::class)->run(),
            'emails' => fn () => app(EmailInbox::class)->poll(),
            'personal_invoices' => fn () => app(PersonalInvoicing::class)->runRecurring(),
            'personal_reminders' => fn () => app(PersonalReminders::class)->run(),
            'spending_checks' => fn () => app(PersonalReminders::class)->spendingCheck(),
            'envelopes' => fn () => app(Envelopes::class)->remindDue(),
            'follow_ups' => fn () => app(FollowUps::class)->run(),
            'tasks' => fn () => app(Tasks::class)->run(),
            'keep_in_touch' => fn () => app(KeepInTouch::class)->run(),
        ] as $job => $task) {
            try {
                $result[$job] = $task();
            } catch (Throwable $e) {
                $result[$job] = 'error';
                Log::warning("Heartbeat {$job} failed: ".$e->getMessage());
            }
        }

        Setting::put('heartbeat_at', now()->toIso8601String());

        return $result;
    }

    /**
     * Leave the next wake-up, unless one is already waiting. Returns true when one was set.
     * The wake-up is a tiny background `sleep; curl` on the server that calls the ping URL.
     */
    public function scheduleNext(): bool
    {
        if (! self::canWake() || ! Cache::add('heartbeat-next', now()->timestamp + self::WAKE_AFTER_SECONDS, self::WAKE_AFTER_SECONDS + 60)) {
            return false;
        }

        $url = route('reminders.ping', ['token' => PushNotifier::cronToken(), 'wake' => 1]);
        $command = sprintf('nohup sh -c %s >/dev/null 2>&1 &', escapeshellarg('sleep '.self::WAKE_AFTER_SECONDS.'; curl -fsS -m 150 '.escapeshellarg($url).' >/dev/null 2>&1'));

        try {
            exec($command);
            Setting::put('heartbeat_chain', 'on');

            return true;
        } catch (Throwable $e) {
            Cache::forget('heartbeat-next');
            Setting::put('heartbeat_chain', 'off: '.mb_substr($e->getMessage(), 0, 120));

            return false;
        }
    }

    /** The wake-up arrived: it is no longer pending, so the next one can be set. */
    public function woke(): void
    {
        Cache::forget('heartbeat-next');
        Setting::put('heartbeat_woke_at', now()->toIso8601String());
    }

    /** Linux server with background commands allowed (not on Windows dev machines, not in tests). */
    public static function canWake(): bool
    {
        if (app()->runningUnitTests() || PHP_OS_FAMILY === 'Windows' || ! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }
}
