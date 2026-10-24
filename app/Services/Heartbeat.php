<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything that has to happen on its own — the app's built-in clock:
 * morning / night reminders, Meta spend + auto-charge / top-ups, alert emails,
 * monthly personal invoices. Runs right after a page or webhook is served
 * (at most every 5 minutes), so no outside cron is needed.
 */
class Heartbeat
{
    public const EVERY_MINUTES = 5;

    /** Run if the last run was more than 5 minutes ago. */
    public function maybeRun(): ?array
    {
        if (! Cache::add('heartbeat', 1, now()->addMinutes(self::EVERY_MINUTES))) {
            return null;
        }

        return $this->run();
    }

    public function run(): array
    {
        @set_time_limit(120);
        $result = [];

        // Each job on its own: one failing never stops the others.
        foreach ([
            'reminders' => fn () => app(Reminders::class)->run(),
            'billing' => fn () => app(SpendBilling::class)->runAll(),
            'emails' => fn () => app(EmailInbox::class)->poll(),
            'personal_invoices' => fn () => app(PersonalInvoicing::class)->runRecurring(),
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
}
