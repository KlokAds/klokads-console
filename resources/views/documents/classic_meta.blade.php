<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 56px 64px 48px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1c1e21; font-size: 11px; }
    .muted { color: #606770; }
    .faint { color: #8d949e; }
    .label { color: #606770; font-size: 10.5px; }
    .value { font-weight: bold; font-size: 11px; margin-top: 2px; }
    hr { border: 0; border-top: 1px solid #ccd0d5; margin: 14px 0; }
    .dash { border-top: 1px dashed #ccd0d5; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 10px; color: #8d949e; }
</style>
</head>
<body>
@php
    $clientName = $client->company ? "{$client->name} ({$client->company})" : $client->name;
    $isReceipt = $type === 'receipt';
@endphp

<table>
    <tr>
        <td>
            <div style="font-size:17px">{{ $isReceipt ? 'Receipt' : ($gst['charged'] ? 'Tax invoice' : 'Invoice') }} for {{ $clientName }}</div>
            <div class="muted" style="margin-top:3px">Account ID: {{ $ad_account_id ?: 'KA-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT) }}</div>
        </td>
        <td style="width:40%"><div style="float:right">@include('documents.partials.logo', ['name' => $business['name'], 'size' => 22])</div></td>
    </tr>
</table>
<hr>

<table style="margin-top:22px">
    <tr>
        <td style="width:55%">
            <div class="label">{{ $isReceipt ? 'Invoice/Payment Date' : 'Invoice Date' }}</div>
            <div class="value">{{ ($isReceipt ? $paid_at : $issued_at)?->format('M j, Y, g:i A') }}</div>

            @if ($isReceipt)
                <div class="label" style="margin-top:16px">Payment method</div>
                <div class="value">{{ $paid_with ?: 'Card' }}</div>
                <div class="muted" style="margin-top:1px">Reference Number: {{ $number }}</div>

                <div class="label" style="margin-top:16px">Transaction ID</div>
                <div class="value">{{ $transaction_id ?: $number }}</div>
            @else
                <div class="label" style="margin-top:16px">Due Date</div>
                <div class="value">{{ $due_at?->format('M j, Y') ?: 'On receipt' }}</div>
                <div class="muted" style="margin-top:1px">Reference Number: {{ $number }}</div>
            @endif

            <div class="label" style="margin-top:16px">Product Type</div>
            <div class="value">{{ $service }}</div>
        </td>
        <td style="text-align:right">
            <div style="font-size:17px;margin-top:34px">{{ $isReceipt ? 'Paid' : 'Amount due' }}</div>
            <div style="font-size:30px;margin-top:6px">{{ $cash($isReceipt ? $amount_paid : $amount_due) }}</div>
            <div class="muted" style="margin-top:6px">
                @if ($period_start)
                    Ads spend since {{ $period_start->format('M j, Y') }}.
                @elseif (! $isReceipt && $auto_charge)
                    Charged automatically to your saved card.
                @endif
            </div>
        </td>
    </tr>
</table>

<hr style="margin-top:48px">

<div style="font-weight:bold;font-size:12.5px;margin:16px 0 10px">{{ $campaigns ? 'Campaigns' : 'Items' }}</div>
<table>
    @if ($campaigns)
        @foreach ($campaigns as $c)
            <tr><td colspan="3" style="border-top:1px solid #ccd0d5;padding-top:9px"></td></tr>
            <tr>
                <td colspan="2">
                    <div style="font-weight:bold">{{ $c['name'] }}</div>
                    @if ($period_start && $period_end)
                        <div style="margin-top:3px">From {{ $period_start->format('M j, Y') }}, 12:00 AM to {{ $period_end->format('M j, Y') }}, 11:59 PM</div>
                    @endif
                </td>
                <td style="text-align:right;font-weight:bold;vertical-align:middle">{{ $cash($c['amount']) }}</td>
            </tr>
            <tr><td colspan="3" class="dash" style="padding-top:9px"></td></tr>
            <tr>
                <td style="padding:0 0 9px 76px;width:45%">{{ $c['detail'] ?? $c['name'] }}</td>
                <td style="padding-bottom:9px">{{ isset($c['quantity']) ? number_format($c['quantity']).' '.($c['unit'] ?? '') : '' }}</td>
                <td style="text-align:right;padding-bottom:9px">{{ $cash($c['amount']) }}</td>
            </tr>
        @endforeach
        <tr><td colspan="3" style="border-top:1px solid #ccd0d5;padding:22px 0 10px;font-weight:bold;font-size:12.5px">Summary</td></tr>
    @endif
    @foreach ($lines as $line)
        <tr>
            <td colspan="2" style="border-top:1px solid #ccd0d5;padding:9px 0">{{ $line['description'] }}</td>
            <td style="border-top:1px solid #ccd0d5;padding:9px 0;text-align:right">{{ $cash($line['amount']) }}</td>
        </tr>
    @endforeach
    @if ($tax)
        <tr>
            <td colspan="2" style="border-top:1px solid #ccd0d5;padding:9px 0">{{ $gst['label'] }} ({{ rtrim(rtrim(number_format($gst['rate'], 2), '0'), '.') }}%)</td>
            <td style="border-top:1px solid #ccd0d5;padding:9px 0;text-align:right">{{ $cash($tax) }}</td>
        </tr>
    @endif
    <tr>
        <td colspan="2" style="border-top:1px solid #ccd0d5;padding:9px 0;font-weight:bold">Total</td>
        <td style="border-top:1px solid #ccd0d5;padding:9px 0;text-align:right;font-weight:bold">{{ $cash($total) }}</td>
    </tr>
</table>

@if (! $isReceipt && $pay_url)
    <div style="margin-top:22px" class="muted">Pay securely online: <span style="color:#5b45e8">{{ $pay_url }}</span></div>
@endif

<div class="footer">
    <table>
        <tr>
            <td style="border-bottom:1px solid #ccd0d5;padding-bottom:6px">
                {{ $business['legal_name'] }}<br>
                @if ($business['address']){!! nl2br(e($business['address'])) !!}<br>@endif
                @if ($business['uen'])UEN: {{ $business['uen'] }}<br>@endif
                @if ($gst['charged'] && $gst['reg_no']){{ $gst['label'] }} Reg. No. {{ $gst['reg_no'] }}@endif
            </td>
            <td style="border-bottom:1px solid #ccd0d5;padding-bottom:6px;text-align:right;vertical-align:bottom">
                {{ $client->country ?? '' }}<br>
                {{ $isReceipt ? 'Receipt' : 'Invoice' }} # {{ $number }}
            </td>
        </tr>
    </table>
    <div style="margin-top:12px">{{ $business['email'] ? 'Questions? '.$business['email'] : '' }}</div>
</div>
</body>
</html>
