<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\PersonalInvoice;
use App\Models\PushSubscription;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Reminders that must land at a set time, each sent once:
 *  - money I owe (with a date): 2 days before, 1 day before, the morning of, and 1 hour before the day ends
 *  - money owed to me (with a date): from 2 days before, every morning until it is due, then every 3 days
 *  - a job invoice made but not sent yet; a job's pay day
 * No date, no reminder.
 */
class PersonalReminders
{
    public const MORNING = '09:00';

    public const LAST_HOUR = '23:00';

    public function __construct(private Notify $notify) {}

    public static function timezone(): string
    {
        return Setting::get('personal_timezone') ?: (PushSubscription::query()->value('timezone') ?: 'Asia/Dhaka');
    }

    public function run(?CarbonImmutable $now = null): int
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(self::timezone());

        return $this->loans($local) + $this->invoices($local);
    }

    private function loans(CarbonImmutable $local): int
    {
        $today = $local->startOfDay();
        $time = $local->format('H:i');
        $sent = 0;

        foreach (Loan::with('payments')->whereNotNull('due_on')->get() as $loan) {
            if (($left = $loan->outstanding()) <= 0) {
                continue;
            }
            $due = CarbonImmutable::parse($loan->due_on->toDateString(), self::timezone())->startOfDay();
            $days = (int) $today->diffInDays($due, false); // 2 = in two days, 0 = today, -1 = yesterday
            $amount = Notify::money($left, $loan->currency);
            $key = "loan:{$loan->id}:{$due->toDateString()}";
            $url = route('personal.loans.index');

            if ($loan->direction === 'borrowed') {
                $when = match (true) {
                    $days === 2 && $time >= self::MORNING => ['d2', "🤝 Pay {$loan->person} {$amount} in 2 days", 'Due '.$due->format('l, j M').'.'],
                    $days === 1 && $time >= self::MORNING => ['d1', "🤝 Pay {$loan->person} {$amount} tomorrow", 'Due '.$due->format('l, j M').'.'],
                    $days === 0 && $time >= self::LAST_HOUR => ['last', "⏰ 1 hour left: pay {$loan->person} {$amount}", 'Today is the day you promised.'],
                    $days === 0 && $time >= self::MORNING => ['d0', "📌 Today: pay {$loan->person} {$amount}", 'You promised to pay back today.'],
                    $days < 0 && $time >= self::MORNING && abs($days) % 3 === 0 => ['late'.abs($days), "⚠️ {$loan->person}: {$amount} overdue", abs($days).' days late.'],
                    default => null,
                };
            } else {
                $when = match (true) {
                    $days >= 0 && $days <= 2 && $time >= self::MORNING => ["d{$days}", "💬 {$loan->person} owes you {$amount}",
                        ($days === 0 ? 'Due today' : 'Due '.($days === 1 ? 'tomorrow' : 'in 2 days')).' — time to knock.'.($loan->phone ? " 📞 {$loan->phone}" : '')],
                    $days < 0 && $time >= self::MORNING && abs($days) % 3 === 0 => ['late'.abs($days), "💬 {$loan->person} still owes you {$amount}", abs($days).' days past the date.'.($loan->phone ? " 📞 {$loan->phone}" : '')],
                    default => null,
                };
            }

            if ($when) {
                $sent += (int) $this->notify->once("{$key}:{$when[0]}", 'reminder', $when[1], $when[2].($loan->note ? " · {$loan->note}" : ''), $url);
            }
        }

        return $sent;
    }

    private function invoices(CarbonImmutable $local): int
    {
        if ($local->format('H:i') < self::MORNING) {
            return 0;
        }
        $today = $local->toDateString();
        $sent = 0;

        foreach (PersonalInvoice::with('client')->whereNotNull('personal_recurring_id')->whereIn('status', ['unpaid', 'partial'])->get() as $invoice) {
            $who = $invoice->client->company ?: $invoice->client->name;
            $amount = Notify::money($invoice->due(), $invoice->currency);
            $url = route('personal.invoices.index', ['invoice' => $invoice->id]);

            // Made but not sent: remind the next morning.
            if (! $invoice->sent_at && $invoice->issued_on->toDateString() < $today) {
                $sent += (int) $this->notify->once("pinv:{$invoice->id}:unsent", 'invoice', "🧾 Send {$who}'s invoice", "{$invoice->number} · {$amount} is still not sent.", $url);
            }
            // Pay day.
            if ($invoice->due_on?->toDateString() === $today) {
                $sent += (int) $this->notify->once("pinv:{$invoice->id}:payday", 'invoice', "💼 {$who} pays {$amount} today", "{$invoice->number} — if it doesn't arrive, give a knock.", $url);
            }
        }

        return $sent;
    }
}
