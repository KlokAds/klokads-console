<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientStatement;
use App\Services\AdStatements;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** A prepaid client's statement: look it over, choose how the fee shows, then send it. */
class StatementController extends Controller
{
    public function __construct(private AdStatements $statements) {}

    /** "Statement for this period" from the Ad money page. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'integer', 'exists:clients,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $statement = $this->statements->draft(Client::findOrFail($data['client']), CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']));

        return redirect()->route('statements.show', $statement);
    }

    public function show(ClientStatement $statement): Response
    {
        if ($statement->status !== 'sent') {
            $statement = $this->statements->draft($statement->client, CarbonImmutable::parse($statement->period_start), CarbonImmutable::parse($statement->period_end));
        }

        return Inertia::render('Statements/Show', [
            'statement' => [
                ...$statement->only('id', 'status', 'sent_to', 'show_fee', 'data'),
                'period_start' => $statement->period_start->toDateString(),
                'period_end' => $statement->period_end->toDateString(),
                'sent_at' => $statement->sent_at?->toIso8601String(),
                'lines' => AdStatements::lines($statement->data, $statement->show_fee),
                'lines_folded' => AdStatements::lines($statement->data, false),
                'lines_with_fee' => AdStatements::lines($statement->data, true),
            ],
            'client' => $statement->client->only('id', 'name', 'company', 'email'),
            'mailReady' => MailSettings::configured(),
        ]);
    }

    public function update(Request $request, ClientStatement $statement): RedirectResponse
    {
        abort_if($statement->status === 'sent', 409, 'This statement was already sent.');
        $statement->update($request->validate(['show_fee' => ['required', 'boolean']]));

        return back();
    }

    public function send(ClientStatement $statement): RedirectResponse
    {
        abort_if($statement->status === 'sent', 409, 'This statement was already sent.');
        try {
            $this->statements->send($statement);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Statement sent to '.$statement->sent_to.'.');
    }
}
