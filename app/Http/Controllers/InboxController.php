<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\InboxMessage;
use App\Models\Setting;
use App\Services\AutoEntries;
use App\Services\EmailInbox;
use App\Support\Catalog;
use App\Support\InboxRules;
use App\Support\TransactionParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Auto entries: the SMS forwarder webhook, the alert-email mailbox, and the review inbox. */
class InboxController extends Controller
{
    public function __construct(private AutoEntries $entries) {}

    /**
     * An Android SMS-forwarder app posts each bank / wallet SMS here.
     * Accepts the common shapes: {from|sender|phone|address, text|message|body|msg|content}.
     */
    public function sms(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(AutoEntries::token(), $token), 404);

        $input = $request->all() ?: (json_decode((string) $request->getContent(), true) ?? []);
        $pick = fn (array $keys) => collect($keys)->map(fn ($k) => data_get($input, $k))->first(fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        $text = $pick(['text', 'message', 'body', 'msg', 'content', 'sms', 'data.text', 'data.message']);
        if (! $text) {
            return response()->json(['ok' => false, 'error' => 'No message text'], 422);
        }

        // The MH-Pilot app also forwards notifications from wallet apps (nSave, Rizon, Elevate Pay…).
        $source = in_array($request->input('source'), ['app', 'notification'], true) ? 'app' : 'sms';
        $message = $this->entries->ingest($source, $pick(['from', 'sender', 'phone', 'address', 'number', 'app', 'data.from']), (string) $text);

        return response()->json(['ok' => true, 'status' => $message->status, 'note' => $message->note, 'account' => $message->account?->name]);
    }

    /** Senders, wallet apps and ignore words, from the admin panel (the phone app edits the same list). */
    public function saveRules(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'senders' => ['array', 'max:200'],
            'senders.*.name' => ['required', 'string', 'max:40'],
            'senders.*.type' => ['nullable', 'in:sms,app'],
            'senders.*.on' => ['boolean'],
            'ignore' => ['array', 'max:200'],
            'ignore.*' => ['string', 'max:60'],
            'whitelist' => ['boolean'],
        ]);
        InboxRules::save($data);

        return back()->with('success', 'Saved — the phone picks it up next time it opens.');
    }

    public function index(Request $request): Response
    {
        $request->session()->put('mode', 'personal');
        $status = $request->input('status', 'review');

        return Inertia::render('Personal/Inbox', [
            'messages' => InboxMessage::with('account:id,name,currency')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('id')->limit(100)->get()
                ->map(fn (InboxMessage $m) => $m->only('id', 'source', 'sender', 'body', 'parsed', 'status', 'note', 'account_id', 'money_transaction_id', 'personal_invoice_id')
                    + ['account' => $m->account?->name, 'received_at' => $m->received_at?->toIso8601String()]),
            'counts' => InboxMessage::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'status' => $status,
            'accounts' => Account::where('archived', false)->orderBy('scope', 'desc')->orderBy('name')->get(['id', 'name', 'currency', 'scope', 'provider']),
            'categories' => [
                'expense' => Catalog::options(Catalog::PERSONAL_CATEGORIES),
                'income' => Catalog::options(Catalog::INCOME_CATEGORIES['personal']),
            ],
            'rules' => InboxRules::all(),
            'setup' => [
                'sms_url' => route('inbox.sms', AutoEntries::token()),
                'imap' => [
                    'host' => Setting::get('imap_host'),
                    'port' => Setting::get('imap_port') ?: 993,
                    'username' => Setting::get('imap_username'),
                    'folder' => Setting::get('imap_folder') ?: 'INBOX',
                    'has_password' => (bool) Setting::get('imap_password'),
                    'checked_at' => Setting::get('imap_checked_at'),
                    'error' => Setting::get('imap_error'),
                ],
                'last_sms' => InboxMessage::where('source', 'sms')->latest('id')->value('created_at')?->toIso8601String(),
            ],
        ]);
    }

    /** Add a message that needed a hand: pick the account (and category). */
    public function add(Request $request, InboxMessage $message): RedirectResponse
    {
        abort_unless($message->parsed, 422);
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'category' => ['nullable', 'string', 'max:40'],
        ]);

        $this->entries->process($message, (int) $data['account_id'], $data['category'] ?? null);
        $message->refresh();

        return back()->with($message->status === 'review' ? 'error' : 'success', $message->status === 'review' ? ($message->note ?: 'Could not add it.') : 'Added.');
    }

    public function ignore(InboxMessage $message): RedirectResponse
    {
        $message->update(['status' => 'ignored', 'note' => 'Ignored by me']);

        return back()->with('success', 'Ignored.');
    }

    public function undo(InboxMessage $message): RedirectResponse
    {
        $this->entries->undo($message);

        return back()->with('success', 'Entry removed — the message is back in review.');
    }

    public function saveImap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:120'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'folder' => ['nullable', 'string', 'max:80'],
        ]);

        Setting::put('imap_host', $data['host'] ?? null);
        Setting::put('imap_port', isset($data['port']) ? (string) $data['port'] : null);
        Setting::put('imap_username', $data['username'] ?? null);
        Setting::put('imap_folder', $data['folder'] ?? null);
        if (filled($data['password'] ?? null)) {
            Setting::put('imap_password', $data['password']);
        }

        return $this->check(app(EmailInbox::class), 'Mailbox saved');
    }

    public function checkNow(EmailInbox $inbox): RedirectResponse
    {
        return $this->check($inbox, 'Checked');
    }

    private function check(EmailInbox $inbox, string $prefix): RedirectResponse
    {
        if (! EmailInbox::configured()) {
            return back()->with('success', "{$prefix}.");
        }

        try {
            $n = $inbox->poll(force: true);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$prefix} — {$n} new email".($n === 1 ? '' : 's').' read.');
    }

    /** Try the reader on any text, without saving (for the setup screen). */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['sender' => ['nullable', 'string', 'max:120'], 'text' => ['required', 'string', 'max:2000']]);

        return response()->json(['parsed' => TransactionParser::parse($data['sender'] ?? null, $data['text'])]);
    }
}
