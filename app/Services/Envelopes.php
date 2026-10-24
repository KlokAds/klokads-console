<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Envelope;
use App\Models\EnvelopeMonth;
use App\Models\MoneyTransaction;
use App\Support\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly envelopes: e.g. Songsar ৳15,000 from bKash. Handing it out debits the source
 * account once (by hand, or when the bKash SMS for that amount arrives); after that,
 * spends by purpose only reduce what is left in the envelope.
 */
class Envelopes
{
    public function __construct(private Notify $notify) {}

    /** Hand out a month's money: one debit from the source account. */
    public function assign(Envelope $envelope, string $month, ?int $amount = null, ?int $accountId = null, ?string $date = null, int $fee = 0, ?string $source = null): EnvelopeMonth
    {
        return DB::transaction(function () use ($envelope, $month, $amount, $accountId, $date, $fee, $source) {
            $amount ??= $envelope->amount;
            $accountId ??= $envelope->account_id;
            $date ??= now()->toDateString();

            $tx = $accountId ? Ledger::save([
                'kind' => 'expense',
                'account_id' => $accountId,
                'amount' => $amount,
                'fee' => $fee,
                'category' => 'envelope',
                'counterparty' => $envelope->name,
                'description' => ($source ? 'Auto · '.$source.' · ' : '').$envelope->name.' · '.CarbonImmutable::parse("{$month}-01")->format('M Y'),
                'occurred_on' => $date,
            ]) : null;

            return EnvelopeMonth::updateOrCreate(
                ['envelope_id' => $envelope->id, 'month' => $month],
                ['assigned' => $amount, 'assigned_on' => $date, 'account_id' => $accountId, 'money_transaction_id' => $tx?->id],
            );
        });
    }

    /** Undo a hand-out: its debit is removed too. */
    public function unassign(EnvelopeMonth $month): void
    {
        DB::transaction(function () use ($month) {
            if ($month->money_transaction_id && ($tx = MoneyTransaction::find($month->money_transaction_id))) {
                Ledger::delete($tx);
            }
            $month->delete();
        });
    }

    /**
     * A debit from a bank/wallet alert: if it is exactly an envelope's monthly amount from
     * its own source and that month is not handed out yet, it is the hand-out.
     */
    public function claim(Account $account, int $amount, int $fee, string $date, string $source): ?EnvelopeMonth
    {
        $month = substr($date, 0, 7);
        $envelope = Envelope::where('active', true)->where('account_id', $account->id)->where('amount', $amount)
            ->whereDoesntHave('months', fn ($q) => $q->where('month', $month))->orderBy('sort')->first();

        return $envelope ? $this->assign($envelope, $month, $amount, $account->id, $date, $fee, $source)->load('envelope') : null;
    }

    /**
     * Each envelope this month: handed out, spent, left, and spent by purpose.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(string $month): array
    {
        return Envelope::with(['account:id,name,currency,last4', 'months' => fn ($q) => $q->where('month', $month), 'spends' => fn ($q) => $q->where('month', $month)->latest('spent_on')->latest('id')])
            ->orderBy('sort')->orderBy('name')->get()
            ->map(function (Envelope $e) {
                $handed = $e->months->first();
                $spent = (int) $e->spends->sum('amount');
                $assigned = (int) ($handed?->assigned ?? 0);

                return [
                    ...$e->only('id', 'name', 'emoji', 'currency', 'amount', 'account_id', 'day', 'active', 'sort'),
                    'purposes' => $e->purposes ?? [],
                    'account' => $e->account?->name,
                    'assigned' => $handed ? $assigned : null,
                    'assigned_on' => $handed?->assigned_on?->toDateString(),
                    'month_id' => $handed?->id,
                    'spent' => $spent,
                    'left' => $assigned - $spent,
                    'by_purpose' => $e->spends->groupBy('purpose')->map(fn ($list) => (int) $list->sum('amount'))->sortDesc(),
                    'spends' => $e->spends->map(fn ($s) => [...$s->only('id', 'purpose', 'amount', 'note'), 'spent_on' => $s->spent_on->toDateString()])->values(),
                ];
            })->values()->all();
    }

    /** On the envelope's day, if it is not handed out yet: remind once. */
    public function remindDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $month = $today->format('Y-m');
        $sent = 0;

        foreach (Envelope::with('account')->where('active', true)->where('day', '<=', $today->day)->whereDoesntHave('months', fn ($q) => $q->where('month', $month))->get() as $e) {
            $sent += (int) $this->notify->once("envelope:{$e->id}:{$month}", 'reminder', "{$e->emoji} Hand out {$e->name} ".Notify::money($e->amount, $e->currency),
                ($e->account ? "From {$e->account->name}. " : '').'When you send it, the SMS fills it in — or tap to mark it handed out.', route('personal.plan'));
        }

        return $sent;
    }
}
