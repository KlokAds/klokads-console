<?php

namespace App\Services;

use App\Mail\StatementMail;
use App\Models\Client;
use App\Models\ClientBalanceEntry;
use App\Models\ClientStatement;
use App\Models\MetaSpendDay;
use App\Support\Business;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Monthly statements for prepaid ad clients: opening balance, top-ups, each campaign's spend,
 * the service fee, closing balance. Drafted on the 1st for last month; I review it and send it.
 */
class AdStatements
{
    public function __construct(private Notify $notify) {}

    /**
     * Everything the statement shows, in cents.
     *
     * @return array<string, mixed>
     */
    public static function build(Client $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $entries = ClientBalanceEntry::where('client_id', $client->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        $spend = (int) $entries->where('kind', 'spend')->sum('spend');
        $fee = (int) $entries->where('kind', 'spend')->sum('commission');

        return [
            'client' => ['name' => $client->company ?: $client->name, 'email' => $client->email],
            'business' => ['name' => Business::profile()['name'], 'email' => Business::profile()['email'] ?? null],
            'currency' => strtoupper($client->currency ?: 'SGD'),
            'opening' => (int) ClientBalanceEntry::where('client_id', $client->id)->where('date', '<', $from)->sum('amount'),
            'closing' => (int) ClientBalanceEntry::where('client_id', $client->id)->where('date', '<=', $to)->sum('amount'),
            'top_ups' => $entries->filter(fn ($e) => $e->amount > 0)->map(fn ($e) => [
                'date' => $e->date->toDateString(), 'amount' => (int) $e->amount, 'note' => $e->kind === 'top_up' ? 'Card payment' : ($e->note ?: 'Payment'),
            ])->values()->all(),
            'adjustments' => $entries->filter(fn ($e) => $e->amount < 0 && $e->kind !== 'spend')->map(fn ($e) => [
                'date' => $e->date->toDateString(), 'amount' => (int) $e->amount, 'note' => $e->note ?: 'Adjustment',
            ])->values()->all(),
            // Spend per campaign from Meta for the days that came out of the balance.
            'campaigns' => MetaSpendDay::where('client_id', $client->id)->whereBetween('date', [$from, $to])->get()
                ->groupBy('campaign_id')->map(fn ($days) => [
                    'name' => $days->first()->campaign_name,
                    'spend' => (int) $days->sum('spend'),
                    'impressions' => (int) $days->sum('impressions'),
                    'clicks' => (int) $days->sum('clicks'),
                ])->filter(fn ($c) => $c['spend'] > 0)->sortByDesc('spend')->values()->all(),
            'spend' => $spend,
            'fee' => $fee,
            'fee_rate' => $spend > 0 ? round($fee / $spend * 100, 1) : null,
        ];
    }

    /**
     * What came out of the balance, as the client sees it: each campaign, then the service fee —
     * or, with the fee folded in, each campaign's share of spend + fee.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{label: string, detail: ?string, amount: int}>
     */
    public static function lines(array $data, bool $showFee): array
    {
        $factor = ! $showFee && $data['spend'] > 0 ? ($data['spend'] + $data['fee']) / $data['spend'] : 1;
        $lines = array_map(fn ($c) => [
            'label' => $c['name'],
            'detail' => number_format($c['impressions']).' impressions · '.number_format($c['clicks']).' clicks',
            'amount' => (int) round($c['spend'] * $factor),
        ], $data['campaigns']);
        if ($showFee && $data['fee'] > 0) {
            $lines[] = ['label' => 'Service fee'.($data['fee_rate'] ? " ({$data['fee_rate']}%)" : ''), 'detail' => 'Campaign management', 'amount' => $data['fee']];
        }
        // Rounding of the folded shares: the last line takes the difference, so the total is exact.
        if (! $showFee && $lines) {
            $lines[count($lines) - 1]['amount'] += ($data['spend'] + $data['fee']) - array_sum(array_column($lines, 'amount'));
        }

        return $lines;
    }

    /** The statement for a period: a fresh draft (rebuilt while still a draft), or the one already sent. */
    public function draft(Client $client, CarbonImmutable $start, CarbonImmutable $end): ClientStatement
    {
        $statement = self::find($client, $start, $end)
            ?? new ClientStatement(['client_id' => $client->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()]);
        if ($statement->status !== 'sent') {
            $statement->data = self::build($client, $start, $end);
            $statement->save();
        }

        return $statement;
    }

    /** Heartbeat: in the first days of a month, draft last month's statements and ask me to review them. */
    public function draftMonthly(?CarbonImmutable $now = null): int
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(PersonalReminders::timezone());
        if ($local->day > 3) {
            return 0;
        }
        $start = $local->subMonthNoOverflow()->startOfMonth()->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $made = 0;

        foreach (Client::where('billing_mode', 'prepaid')->whereNotNull('meta_ad_account_id')->get() as $client) {
            if (self::find($client, $start, $end)
                || ! ClientBalanceEntry::where('client_id', $client->id)->where('date', '<=', $end->toDateString())->exists()) {
                continue;
            }
            $statement = $this->draft($client, $start, $end);
            $this->notify->send('billing', '📄 '.($client->company ?: $client->name).': '.$start->format('F').' statement ready',
                'Spend '.Notify::money($statement->data['spend'], $statement->data['currency']).' · closing balance '.Notify::money($statement->data['closing'], $statement->data['currency']).'. Check it and press Send.',
                route('statements.show', $statement), 'klok-statement');
            $made++;
        }

        return $made;
    }

    /** Dates compared as days: the database may keep a time with them. */
    private static function find(Client $client, CarbonImmutable $start, CarbonImmutable $end): ?ClientStatement
    {
        return ClientStatement::where('client_id', $client->id)
            ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->first();
    }

    public function send(ClientStatement $statement): void
    {
        if (! MailSettings::configured()) {
            throw new RuntimeException('Email is not set up yet — add your SMTP details in Settings → Email.');
        }
        $client = $statement->client;
        if (! $client->email) {
            throw new RuntimeException('This client has no email address.');
        }

        try {
            Mail::to($client->email, $client->company ?: $client->name)->send(new StatementMail($statement));
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('The email server refused the message: '.$e->getMessage(), previous: $e);
        }
        $statement->update(['status' => 'sent', 'sent_to' => $client->email, 'sent_at' => now()]);
    }
}
