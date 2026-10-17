<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 56px 60px 56px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #202124; font-size: 11.5px; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .b { font-weight: bold; }
    .lead td { padding: 5px 0 0; line-height: 1.15; }
    .lead .lbl { width: 1%; white-space: nowrap; padding-right: 3px; }
    .lead .dots { border-bottom: 1.2px dotted #80868b; }
    .lead .val { width: 1%; white-space: nowrap; padding-left: 3px; }
    .rule { border-top: 2px solid #9aa0a6; }
    .grid th { text-align: left; font-weight: bold; padding: 8px 0; border-top: 1px solid #5f6368; border-bottom: 1px solid #5f6368; }
    .grid td { padding: 8px 0; border-bottom: 1px solid #9aa0a6; }
    .r, .grid th.r, .grid td.r { text-align: right; }
    .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 10px; color: #5f6368; }
</style>
</head>
<body>
@php
    $isReceipt = $type === 'receipt';
    $title = $isReceipt ? 'Receipt' : ($gst['charged'] ? 'Tax Invoice' : 'Invoice');
    $rate = rtrim(rtrim(number_format($gst['rate'], 2), '0'), '.');
    $billingId = trim(chunk_split(str_pad((string) ($client->id * 7919 % 1000000000000), 12, '0', STR_PAD_LEFT), 4, '-'), '-');
    $summary = $period_start && $period_end ? 'Summary for '.$period_start->format('M j, Y').' - '.$period_end->format('M j, Y') : 'Summary';
    $rows = $campaigns ?: collect($lines)->map(fn ($l) => ['name' => $l['description'], 'quantity' => null, 'unit' => null, 'amount' => $l['amount']])->all();
@endphp

{{-- Page 1 --}}
<table>
    <tr>
        <td style="width:55%">
            @include('documents.partials.logo', ['name' => $business['name'], 'size' => 30])
            <div style="font-size:30px;margin-top:10px">{{ $title }}</div>
            <div style="margin-top:4px">{{ $isReceipt ? 'Receipt' : 'Invoice' }} number: {{ $number }}</div>
        </td>
        <td class="r" style="line-height:1.65">
            <span class="b">{{ $business['legal_name'] }}</span><br>
            @if ($business['address']){!! nl2br(e($business['address'])) !!}<br>@endif
            @if ($business['uen'])UEN: {{ $business['uen'] }}<br>@endif
            @if ($gst['charged'] && $gst['reg_no']){{ $gst['label'] }} Reg No: {{ $gst['reg_no'] }}@endif
        </td>
    </tr>
</table>

<div style="margin-top:30px;line-height:1.75">
    <div class="b">Bill to</div>
    {{ $client->name }}<br>
    @if ($client->company){{ $client->company }}<br>@endif
    @if (! empty($client->address))
        {!! nl2br(e($client->address)) !!}
    @else
        {{ $client->email }}<br>
        {{ $client->country ?? '' }}
    @endif
</div>

<table style="margin-top:26px">
    <tr>
        <td style="width:48%;padding-right:4%">
            <div class="b" style="margin-bottom:6px">Details</div>
            <table class="lead">
                <tr><td class="lbl">{{ $isReceipt ? 'Receipt' : 'Invoice' }} number</td><td class="dots"></td><td class="val">{{ $number }}</td></tr>
                <tr><td class="lbl">{{ $isReceipt ? 'Payment date' : 'Invoice date' }}</td><td class="dots"></td><td class="val">{{ ($isReceipt ? $paid_at : $issued_at)?->format('M j, Y') }}</td></tr>
                @if (! $isReceipt && $due_at)
                    <tr><td class="lbl">Due date</td><td class="dots"></td><td class="val">{{ $due_at->format('M j, Y') }}</td></tr>
                @endif
                <tr><td class="lbl">Billing ID</td><td class="dots"></td><td class="val">{{ $billingId }}</td></tr>
                @if ($ad_account_id)
                    <tr><td class="lbl">Account ID</td><td class="dots"></td><td class="val">{{ $ad_account_id }}</td></tr>
                @endif
                @if ($isReceipt && $paid_with)
                    <tr><td class="lbl">Paid with</td><td class="dots"></td><td class="val">{{ $paid_with }}</td></tr>
                @endif
            </table>
        </td>
        <td>
            <div class="b" style="padding-bottom:7px">{{ $service }}</div>
            <div class="rule"></div>
            <table style="margin:14px 0">
                <tr>
                    <td style="vertical-align:middle">{{ $isReceipt ? 'Paid in' : 'Total in' }} {{ $currency }}</td>
                    <td class="r b" style="font-size:19px">{{ $money($isReceipt ? $amount_paid : $total) }}</td>
                </tr>
            </table>
            <div class="b" style="padding-bottom:7px">{{ $summary }}</div>
            <div class="rule"></div>
            <table style="margin-top:10px;line-height:1.7">
                <tr><td>Subtotal in {{ $currency }}</td><td class="r">{{ $money($subtotal) }}</td></tr>
                @if ($tax)<tr><td>{{ $gst['label'] }} ({{ $rate }}%)</td><td class="r">{{ $money($tax) }}</td></tr>@endif
                <tr><td>Total in {{ $currency }}</td><td class="r">{{ $money($total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div style="margin-top:34px">
    @if ($isReceipt)
        Paid in full on {{ $paid_at?->format('M j, Y') }}{{ $paid_with ? ' with '.$paid_with : '' }}. Thank you.
    @elseif ($auto_charge)
        You will be automatically charged for any amount due.
    @elseif ($pay_url)
        Please pay by {{ $due_at?->format('M j, Y') }}: {{ $pay_url }}
    @endif
</div>

{{-- Page 2 --}}
<div style="page-break-before:always"></div>
<table>
    <tr>
        <td style="vertical-align:middle;width:1%;white-space:nowrap">@include('documents.partials.logo', ['name' => $business['name'], 'size' => 24])</td>
        <td style="vertical-align:middle;font-size:18px;padding-left:10px">{{ $title }}</td>
        <td class="r" style="vertical-align:middle;color:#5f6368">{{ $isReceipt ? 'Receipt' : 'Invoice' }} number: {{ $number }}</td>
    </tr>
</table>

<div class="b" style="margin-top:34px;line-height:1.75">
    @if ($ad_account_id)Account ID: {{ $ad_account_id }}<br>@endif
    Account: {{ $client->company ?: $client->name }}<br>
    @if ($period_start && $period_end){{ $period_start->format('M j, Y') }} - {{ $period_end->format('M j, Y') }}@endif
</div>

<table class="grid" style="margin-top:12px">
    <thead>
        <tr><th>Description</th><th class="r" style="width:12%">Quantity</th><th style="width:14%;padding-left:22px">Units</th><th class="r" style="width:16%">Amount({{ $currency }})</th></tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="r">{{ isset($row['quantity']) && $row['quantity'] !== null ? $row['quantity'] : '' }}</td>
                <td style="padding-left:22px">{{ $row['unit'] ?? '' }}</td>
                <td class="r">{{ number_format($row['amount'] / 100, 2) }}</td>
            </tr>
        @endforeach
        @if ($campaigns)
            @foreach ($lines as $line)
                @continue(str_contains(strtolower($line['description']), 'budget'))
                <tr><td>{{ $line['description'] }}</td><td></td><td></td><td class="r">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @endforeach
        @endif
    </tbody>
</table>

<table style="margin-top:12px">
    <tr>
        <td style="width:50%"></td>
        <td>
            <table style="line-height:1.7">
                <tr><td>Subtotal in {{ $currency }}</td><td class="r">{{ $money($subtotal) }}</td></tr>
                @if ($tax)<tr><td>{{ $gst['label'] }} ({{ $rate }}%)</td><td class="r">{{ $money($tax) }}</td></tr>@endif
            </table>
            <div class="rule" style="margin-top:6px"></div>
            <table style="margin-top:8px">
                <tr><td style="vertical-align:middle">Total in {{ $currency }}</td><td class="r b" style="font-size:19px">{{ $money($total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if (collect($rows)->contains(fn ($r) => $r['amount'] < 0))
    <div style="margin-top:40px;color:#5f6368">Credits relate to the original invoice or month of service noted. Invoice numbers associated with a credit might have changed if there was a rebill.</div>
@endif

@if ($business['email'])
    <div style="margin-top:14px;color:#5f6368">Questions about this {{ $isReceipt ? 'receipt' : 'invoice' }}? {{ $business['email'] }}</div>
@endif

@include('documents.partials.page-numbers', ['color' => [0.37, 0.39, 0.41]])
</body>
</html>
