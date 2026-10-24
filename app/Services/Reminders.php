<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\InboxMessage;
use App\Models\Invoice;
use App\Models\Loan;
use App\Models\MoneyTransaction;
use App\Models\PersonalInvoice;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Morning and night reminders, sent by the cron-job.org ping (every few minutes).
 * Each device gets each reminder once a day, at or after its own local time.
 */
class Reminders
{
    public function __construct(private PushNotifier $push) {}

    /** @return array{sent: int, checked: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;
        $subs = PushSubscription::all();

        foreach ($subs as $sub) {
            $local = $now->setTimezone($sub->timezone ?: 'Asia/Singapore');
            $today = $local->toDateString();

            if ($sub->morning && $local->format('H:i') >= $sub->morning_at && $sub->last_morning_on?->toDateString() !== $today) {
                $sub->update(['last_morning_on' => $today]);
                $sent += (int) $this->push->send($sub, $this->morning($local));
            }

            if ($sub->exists && $sub->evening && $local->format('H:i') >= $sub->evening_at && $sub->last_evening_on?->toDateString() !== $today) {
                $sub->update(['last_evening_on' => $today]);
                $sent += (int) $this->push->send($sub, $this->evening($local));
            }
        }

        Setting::put('cron_last_ping', $now->toIso8601String());

        return ['sent' => $sent, 'checked' => $subs->count()];
    }

    /**
     * What needs doing today: bills, budgets near their limit, client invoices due.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function morning(CarbonImmutable $local): array
    {
        $lines = [];

        // Bills: overdue, due today, due in the next 3 days — not paid this month.
        $paid = MoneyTransaction::whereNotNull('bill_id')
            ->whereBetween('occurred_on', [$local->startOfMonth()->toDateString(), $local->endOfMonth()->toDateString()])
            ->pluck('bill_id')->all();
        $bills = Bill::with('account:id,currency')->where('active', true)->whereNotIn('id', $paid)->orderBy('day')->get();
        $day = $local->day;
        $overdue = $bills->filter(fn ($b) => $b->day < $day);
        $today = $bills->filter(fn ($b) => $b->day === $day);
        $soon = $bills->filter(fn ($b) => $b->day > $day && $b->day <= $day + 3);

        if ($overdue->isNotEmpty()) {
            $lines[] = '⚠️ Overdue: '.$this->billList($overdue);
        }
        if ($today->isNotEmpty()) {
            $lines[] = '📌 Due today: '.$this->billList($today);
        }
        if ($soon->isNotEmpty()) {
            $lines[] = '🗓️ Coming up: '.$this->billList($soon, withDay: true);
        }

        // Budgets at 80% or more this month.
        foreach ($this->budgetWarnings($local) as $warning) {
            $lines[] = $warning;
        }

        // Business: client invoices overdue or due today.
        $open = Invoice::where('status', 'open')->whereNotNull('due_at');
        $late = (clone $open)->where('due_at', '<', $local->startOfDay()->utc())->get(['amount_remaining', 'currency']);
        $dueToday = (clone $open)->whereBetween('due_at', [$local->startOfDay()->utc(), $local->endOfDay()->utc()])->count();
        if ($late->isNotEmpty()) {
            $lines[] = "💼 {$late->count()} client invoice".($late->count() === 1 ? '' : 's').' overdue ('.$this->money((int) $late->sum('amount_remaining'), $late->first()->currency).')';
        }
        if ($dueToday) {
            $lines[] = "💼 {$dueToday} client invoice".($dueToday === 1 ? '' : 's').' due today';
        }

        // My own invoices: overdue, and payments clients say they made (on hold).
        $open = PersonalInvoice::whereIn('status', ['unpaid', 'partial'])->get();
        $late = $open->filter(fn ($i) => $i->due_on && $i->due_on->lt($local->startOfDay()));
        if ($late->isNotEmpty()) {
            $lines[] = "🧾 {$late->count()} of my invoice".($late->count() === 1 ? '' : 's').' overdue ('.$this->money((int) $late->sum(fn ($i) => $i->due()), 'BDT').')';
        }
        if ($held = $open->whereNotNull('reported')->count()) {
            $lines[] = "⏳ {$held} payment".($held === 1 ? '' : 's').' on hold — check and confirm';
        }

        // Dena-paona due within 3 days or overdue.
        foreach (Loan::with('payments')->whereNotNull('due_on')->whereDate('due_on', '<=', $local->addDays(3)->toDateString())->orderBy('due_on')->get() as $loan) {
            if (($left = $loan->outstanding()) <= 0 || count($lines) >= 6) {
                continue;
            }
            $when = $loan->due_on->lt($local->startOfDay()) ? 'overdue' : ($loan->due_on->isSameDay($local) ? 'due today' : 'due '.$loan->due_on->format('j M'));
            $lines[] = $loan->direction === 'lent'
                ? "🤝 {$loan->person} owes you ".$this->money($left, $loan->currency)." ({$when})"
                : "🤝 Pay {$loan->person} ".$this->money($left, $loan->currency)." ({$when})";
        }

        // A goal deadline within a month.
        $goal = Goal::with('account')->where('done', false)->whereNotNull('due_on')
            ->whereBetween('due_on', [$local->toDateString(), $local->addDays(30)->toDateString()])->orderBy('due_on')->first();
        if ($goal && ($left = $goal->target - Ledger::balance($goal->account)) > 0) {
            $lines[] = "🎯 {$goal->name}: ".$this->money($left, $goal->account->currency).' to go by '.$goal->due_on->format('j M');
        }

        $count = count($lines);

        return [
            'title' => $count ? "☀️ Good morning — {$count} thing".($count === 1 ? '' : 's').' today' : '☀️ Good morning — nothing due today',
            'body' => $count ? implode("\n", array_slice($lines, 0, 5)) : 'No bills due, budgets on track. Have a good day!',
            'url' => route('personal.dashboard'),
            'tag' => 'klok-morning',
        ];
    }

    /**
     * End of day: what was spent today, or a nudge to add it.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function evening(CarbonImmutable $local): array
    {
        $ids = Account::where('scope', 'personal')->pluck('id');
        $entries = MoneyTransaction::with('account:id,currency')->whereIn('account_id', $ids)
            ->whereDate('occurred_on', $local->toDateString())->get();
        $spent = $entries->where('kind', 'expense')->reject(fn ($t) => array_key_exists((string) $t->category, Catalog::LOAN_CATEGORIES))
            ->groupBy(fn ($t) => strtoupper($t->account->currency))
            ->map(fn (Collection $list, $currency) => $this->money((int) $list->sum(fn ($t) => $t->amount + $t->fee), $currency));

        $auto = InboxMessage::whereDate('created_at', $local->toDateString())->whereIn('status', ['added', 'matched'])->count();
        $review = InboxMessage::where('status', 'review')->count();
        $autoLine = ($auto ? "\n🤖 {$auto} added automatically today" : '').($review ? "\n👀 {$review} bank/wallet alert".($review === 1 ? '' : 's').' need a look' : '');

        $tomorrow = $local->addDay()->day;
        $bills = Bill::where('active', true)->where('day', $tomorrow)->pluck('name');
        $billLine = $bills->isNotEmpty() ? "\n📌 Tomorrow: ".$bills->take(3)->implode(', ') : '';
        $url = route('money.index', ['scope' => 'personal', 'new' => 1]);

        if ($entries->isEmpty()) {
            return [
                'title' => "🌙 Add today's spending",
                'body' => 'Nothing recorded yet today — it takes 30 seconds. Tap to add.'.$billLine.$autoLine,
                'url' => $url,
                'tag' => 'klok-evening',
            ];
        }

        return [
            'title' => '🌙 Today: '.($spent->isNotEmpty() ? $spent->implode(' · ') : 'no spending').' spent',
            'body' => "{$entries->count()} entr".($entries->count() === 1 ? 'y' : 'ies').' today. Anything missing? Tap to add.'.$billLine.$autoLine,
            'url' => $url,
            'tag' => 'klok-evening',
        ];
    }

    /** @return array<int, string> */
    private function budgetWarnings(CarbonImmutable $local): array
    {
        $budgets = Budget::all();
        if ($budgets->isEmpty()) {
            return [];
        }

        $spent = MoneyTransaction::with('account:id,currency,scope')->where('kind', 'expense')
            ->whereBetween('occurred_on', [$local->startOfMonth()->toDateString(), $local->endOfMonth()->toDateString()])
            ->get()->filter(fn ($t) => $t->account?->scope === 'personal')
            ->groupBy(fn ($t) => strtoupper($t->account->currency).'|'.$t->category)
            ->map(fn ($list) => (int) $list->sum('amount'));

        return $budgets->map(function (Budget $b) use ($spent) {
            $used = $spent[strtoupper($b->currency).'|'.$b->category] ?? 0;
            $label = Catalog::PERSONAL_CATEGORIES[$b->category] ?? $b->category;

            return match (true) {
                $used > $b->amount => "🔴 {$label} over budget by ".$this->money($used - $b->amount, $b->currency),
                $used >= $b->amount * 0.8 => "🟡 {$label} at ".round($used / $b->amount * 100).'% of budget',
                default => null,
            };
        })->filter()->take(2)->values()->all();
    }

    private function billList(Collection $bills, bool $withDay = false): string
    {
        $shown = $bills->take(3)->map(fn ($b) => $b->name.' '.$this->money($b->amount, $b->account->currency).($withDay ? ' ('.$this->ordinal($b->day).')' : ''));

        return $shown->implode(', ').($bills->count() > 3 ? ' +'.($bills->count() - 3).' more' : '');
    }

    private function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    private function money(int $cents, string $currency): string
    {
        $code = strtoupper($currency);
        $symbol = ['SGD' => 'S$', 'BDT' => '৳', 'USD' => '$'][$code] ?? $code.' ';

        return $symbol.number_format($cents / 100, $code === 'BDT' ? 0 : 2);
    }
}
