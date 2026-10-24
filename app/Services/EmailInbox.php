<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads bank / wallet alert emails (DBS, Airwallex, nSave, Rizon…) from one mailbox —
 * e.g. alerts@ that the banks' emails are forwarded to — and feeds them to AutoEntries.
 * Only unread mail is read; it is marked read, never deleted.
 */
class EmailInbox
{
    public function __construct(private AutoEntries $entries) {}

    public static function configured(): bool
    {
        return (bool) (Setting::get('imap_host') && Setting::get('imap_username') && Setting::get('imap_password'));
    }

    /** Check the mailbox (at most every 10 minutes unless forced). Returns messages read. */
    public function poll(bool $force = false): int
    {
        if (! self::configured() || (! $force && ! Cache::add('imap-poll', 1, now()->addMinutes(10)))) {
            return 0;
        }

        $client = (new ClientManager)->make([
            'host' => Setting::get('imap_host'),
            'port' => (int) (Setting::get('imap_port') ?: 993),
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => Setting::get('imap_username'),
            'password' => Setting::get('imap_password'),
            'protocol' => 'imap',
            'timeout' => 20,
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            Setting::put('imap_error', mb_substr($e->getMessage(), 0, 250));
            throw new RuntimeException('Could not open the mailbox: '.$e->getMessage());
        }

        $read = 0;
        $folder = $client->getFolder(Setting::get('imap_folder') ?: 'INBOX');
        foreach ($folder->query()->unseen()->since(now()->subDays(7))->limit(40)->get() as $mail) {
            $from = (string) ($mail->getFrom()[0]->mail ?? '');
            $text = trim((string) $mail->getSubject()."\n".($mail->getTextBody() ?: strip_tags((string) $mail->getHTMLBody())));
            $date = $mail->getDate()?->first();
            $this->entries->ingest('email', $from, $text, $date ? CarbonImmutable::parse((string) $date) : null);
            $mail->setFlag('Seen');
            $read++;
        }

        $client->disconnect();
        Setting::put('imap_checked_at', now()->toIso8601String());
        Setting::put('imap_error', null);

        return $read;
    }
}
