<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Loan;
use App\Models\PersonalClient;
use App\Models\PersonalInvoice;
use App\Models\PersonalRecurring;
use Carbon\CarbonImmutable;

/**
 * Work follow-ups from the phone: a WhatsApp / Messenger / IMO message not seen, a missed
 * call not returned. An hour later I'm reminded who it was and what it's about (their job,
 * an open invoice, money they owe me or I owe them); again after 3 hours if still open.
 */
class FollowUps
{
    public const SOURCES = ['whatsapp' => 'WhatsApp', 'whatsapp_business' => 'WhatsApp Business', 'messenger' => 'Messenger', 'imo' => 'IMO', 'call' => 'Phone'];

    /** Once when it comes in, after an hour, then every 6 hours until I mark it done (or reply / call back). */
    public const FIRST_AFTER_MINUTES = 60;

    public const AGAIN_AFTER_MINUTES = 360;

    /** A week of 6-hourly reminders at most, so a forgotten one cannot ring forever. */
    public const MAX_REMINDERS = 29;

    public function __construct(private Notify $notify) {}

    /**
     * One event from the phone.
     *
     * @param  array{action: string, source: string, kind?: string, person?: ?string, phone?: ?string, text?: ?string, at?: ?int}  $e
     */
    public function record(array $e): ?FollowUp
    {
        $at = isset($e['at']) ? CarbonImmutable::createFromTimestampMs($e['at']) : CarbonImmutable::now();
        $key = self::key($e['source'], $e['person'] ?? null, $e['phone'] ?? null);
        if ($key === null) {
            return null;
        }

        // A real call with family (I called or answered, and it lasted): we talked.
        if ($e['action'] === 'called_back' && ($e['duration'] ?? 1) > 0) {
            app(KeepInTouch::class)->talked($e['phone'] ?? null, $at);
        }

        // I opened the chat, or called them back: done by itself.
        if (in_array($e['action'], ['seen', 'called_back'], true)) {
            $query = FollowUp::open()->where('first_at', '<=', $at->addMinute());
            $e['action'] === 'called_back' && ($digits = self::digits($e['phone'] ?? null))
                ? $query->where('phone', 'like', "%{$digits}")
                : $query->where('key', $key);
            $query->update(['seen_at' => $at]);

            return null;
        }

        $open = FollowUp::open()->where('key', $key)->latest('id')->first();
        if ($open) {
            $open->update(['count' => $open->count + 1, 'last_at' => $at, 'preview' => $this->clip($e['text'] ?? $open->preview),
                'kind' => ($e['kind'] ?? 'message') === 'missed_call' ? 'missed_call' : $open->kind]);

            return $open;
        }

        $f = FollowUp::create([
            'source' => $e['source'], 'kind' => ($e['kind'] ?? 'message') === 'missed_call' ? 'missed_call' : 'message',
            'person' => mb_substr(trim((string) ($e['person'] ?: $e['phone'])), 0, 190), 'phone' => $e['phone'] ?? null,
            'key' => $key, 'preview' => $this->clip($e['text'] ?? null), 'first_at' => $at, 'last_at' => $at,
            'remind_at' => $at->addMinutes(self::FIRST_AFTER_MINUTES),
        ]);

        // Once, right away: it is on my list now.
        $what = $f->kind === 'missed_call' ? '📞 Missed call' : '💬 '.self::SOURCES[$f->source];
        $this->notify->send('reminder', "{$what}: {$f->person}", trim(($f->kind === 'message' && $f->preview ? '“'.$f->preview.'” · ' : '').($this->context($f) ? $this->context($f).' · ' : '').'I\'ll remind you in an hour if it is still open.'),
            route('personal.followups.index'), 'klok-followup');

        return $f;
    }

    /** Heartbeat: remind what is still open after an hour (and again later). */
    public function run(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $sent = 0;

        foreach (FollowUp::open()->whereNotNull('remind_at')->where('remind_at', '<=', $now)->where('reminded', '<', self::MAX_REMINDERS)->get() as $f) {
            $what = $f->kind === 'missed_call'
                ? ($f->source === 'call' ? '📞 Missed call' : '📞 Missed '.self::SOURCES[$f->source].' call')
                : '💬 Unseen on '.self::SOURCES[$f->source];
            $ago = $f->first_at->diffForHumans($now, ['parts' => 1, 'short' => true, 'syntax' => CarbonImmutable::DIFF_ABSOLUTE]);
            $lines = array_filter([
                $f->kind === 'message' && $f->preview ? '“'.$f->preview.'”'.($f->count > 1 ? " (+{$f->count} messages)" : '') : ($f->count > 1 ? "{$f->count} times" : null),
                $this->context($f),
                $f->note ? "📝 {$f->note}" : null,
            ]);
            $this->notify->send('reminder', "{$what}: {$f->person} · {$ago} ago", implode("\n", $lines) ?: 'Not answered yet.', route('personal.followups.index', ['f' => $f->id]), 'klok-followup');

            $f->update(['reminded' => $f->reminded + 1, 'remind_at' => $f->reminded + 1 < self::MAX_REMINDERS ? $now->addMinutes(self::AGAIN_AFTER_MINUTES) : null]);
            $sent++;
        }

        return $sent;
    }

    /** What this person is to me: a job, an open invoice, dena-paona. */
    public function context(FollowUp $f): ?string
    {
        $digits = self::digits($f->phone);
        $name = mb_strtolower(trim($f->person));
        $same = fn (?string $phone, ?string ...$names) => ($digits && $phone && str_ends_with(self::digits($phone) ?? '', $digits))
            || collect($names)->filter()->contains(fn ($n) => mb_strlen($n = mb_strtolower(trim($n))) >= 3 && mb_strlen($name) >= 3 && (str_contains($name, $n) || str_contains($n, $name)));
        $parts = [];

        if ($client = PersonalClient::all()->first(fn ($c) => $same($c->phone, $c->name, $c->company))) {
            $job = PersonalRecurring::where('personal_client_id', $client->id)->where('active', true)->first();
            if ($job) {
                $parts[] = '💼 Job '.Notify::money(PersonalInvoicing::total($job->items), $job->currency).'/month · pay day '.$job->next_on->format('j M');
            }
            $open = PersonalInvoice::where('personal_client_id', $client->id)->whereIn('status', ['unpaid', 'partial'])->get();
            if ($open->isNotEmpty()) {
                $parts[] = '🧾 '.$open->count().' open invoice'.($open->count() > 1 ? 's' : '').' · '.Notify::money((int) $open->sum(fn ($i) => $i->due()), 'BDT').' due';
            }
        }
        foreach (Loan::with('payments')->get()->filter(fn ($l) => $l->outstanding() > 0 && $same($l->phone, $l->person)) as $loan) {
            $parts[] = '🤝 '.($loan->direction === 'lent' ? 'Owes you ' : 'You owe ').Notify::money($loan->outstanding(), $loan->currency).($loan->due_on ? ' · due '.$loan->due_on->format('j M') : '');
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    /** @return array<string, mixed> */
    public function row(FollowUp $f): array
    {
        return [
            ...$f->only('id', 'source', 'kind', 'person', 'phone', 'preview', 'count', 'reminded', 'note'),
            'first_at' => $f->first_at->toIso8601String(),
            'remind_at' => $f->remind_at?->toIso8601String(),
            'seen_at' => $f->seen_at?->toIso8601String(),
            'done_at' => $f->done_at?->toIso8601String(),
            'context' => $this->context($f),
        ];
    }

    public function done(FollowUp $f): void
    {
        $f->update(['done_at' => now(), 'remind_at' => null]);
    }

    /** Remind again in a while; reopens it if it was closed. */
    public function later(FollowUp $f, int $minutes): void
    {
        $f->update(['remind_at' => now()->addMinutes($minutes), 'reminded' => min($f->reminded, self::MAX_REMINDERS - 1), 'done_at' => null, 'seen_at' => null]);
    }

    public static function key(string $source, ?string $person, ?string $phone): ?string
    {
        $who = self::digits($phone) ?? mb_strtolower(trim((string) $person));

        return $who === '' ? null : "{$source}:{$who}";
    }

    /** The last 10 digits of a phone number (so +8801711… and 01711… match). */
    public static function digits(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 7 ? substr($d, -10) : null;
    }

    private function clip(?string $text): ?string
    {
        return $text === null ? null : mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 300);
    }
}
