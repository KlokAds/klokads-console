<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('documents.partials.pro-styles')
</head>
<body>
@php
    $isReceipt = $type === 'receipt';
    $title = $isReceipt ? 'Receipt' : ($gst['charged'] ? 'Tax Invoice' : 'Invoice');
    $rate = rtrim(rtrim(number_format($gst['rate'], 2), '0'), '.');
    $rows = $campaigns ?: collect($lines)->map(fn ($l) => ['name' => $l['description'], 'quantity' => null, 'unit' => null, 'amount' => $l['amount']])->all();
@endphp

<div class="band" style="padding-bottom:26px">
    <table>
        <tr>
            <td>
                @include('documents.partials.logo', ['name' => $business['name'], 'size' => 26, 'dark' => true])
                <div style="font-size:28px;font-weight:bold;margin-top:18px">{{ $title }}</div>
                <div style="color:#b6bbcf;margin-top:4px">No. {{ $number }}</div>
            </td>
            <td class="r" style="color:#d9dce8;line-height:1.7;width:45%">
                <b style="color:#fff">{{ $business['legal_name'] }}</b><br>
                @if ($business['address']){!! nl2br(e($business['address'])) !!}<br>@endif
                @if ($business['uen'])UEN {{ $business['uen'] }}<br>@endif
                @if ($gst['charged'] && $gst['reg_no']){{ $gst['label'] }} Reg No {{ $gst['reg_no'] }}@endif
            </td>
        </tr>
    </table>
</div>
<div class="accent"></div>

<div class="wrap">
    <table>
        <tr>
            <td style="width:50%;padding-right:12px">
                <div class="card" style="min-height:92px;line-height:1.65">
                    <div class="eyebrow">Bill to</div>
                    <b>{{ $client->company ?: $client->name }}</b><br>
                    @if ($client->company){{ $client->name }}<br>@endif
                    @if (! empty($client->address)){!! nl2br(e($client->address)) !!}<br>@endif
                    {{ $client->email }}<br>
                    @if (empty($client->address)){{ $client->country ?? '' }}<br>@endif
                    @if (! empty($client->tax_id))Tax ID: {{ $client->tax_id }}@endif
                </div>
            </td>
            <td>
                <div class="card" style="min-height:92px">
                    <table style="line-height:1.85">
                        <tr><td class="ink5">{{ $isReceipt ? 'Paid on' : 'Invoice date' }}</td><td class="r b">{{ ($isReceipt ? $paid_at : $issued_at)?->format('j M Y') }}</td></tr>
                        @if (! $isReceipt)<tr><td class="ink5">Due date</td><td class="r b">{{ $due_at?->format('j M Y') ?: 'On receipt' }}</td></tr>@endif
                        @if ($ad_account_id)<tr><td class="ink5">Account ID</td><td class="r b">{{ $ad_account_id }}</td></tr>@endif
                        @if ($period_start && $period_end)<tr><td class="ink5">Period</td><td class="r b">{{ $period_start->format('j M') }} – {{ $period_end->format('j M Y') }}</td></tr>@endif
                        @if ($isReceipt && $paid_with)<tr><td class="ink5">Paid with</td><td class="r b">{{ $paid_with }}</td></tr>@endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table style="margin-top:22px">
        <tr>
            <td style="vertical-align:middle"><span class="b" style="font-size:13px">{{ $service }}</span></td>
            <td class="r" style="vertical-align:middle">
                <span class="pill {{ $isReceipt ? 'pill-paid' : 'pill-due' }}">{{ $isReceipt ? 'Paid in full' : ($auto_charge ? 'Auto-charged' : 'Payment due') }}</span>
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-top:10px">
        <thead><tr><th>Description</th><th class="r" style="width:13%">Quantity</th><th style="width:15%">Units</th><th class="r" style="width:18%">Amount ({{ $currency }})</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="b">{{ $row['name'] }}@if (! empty($row['detail']))<br><span class="ink5" style="font-weight:normal">{{ $row['detail'] }}</span>@endif</td>
                    <td class="r">{{ isset($row['quantity']) && $row['quantity'] !== null ? number_format($row['quantity']) : '' }}</td>
                    <td class="ink5">{{ $row['unit'] ?? '' }}</td>
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

    <table style="margin-top:6px">
        <tr>
            <td style="width:55%;padding-top:10px" class="ink5">
                @if ($isReceipt)
                    Received with thanks{{ $paid_with ? ' — '.$paid_with : '' }}.
                @elseif ($auto_charge)
                    You will be automatically charged for any amount due.
                @elseif ($pay_url)
                    Pay securely online:<br><span style="color:#5b45e8">{{ $pay_url }}</span>
                @endif
            </td>
            <td>
                <table class="tot">
                    <tr><td class="ink5">Subtotal in {{ $currency }}</td><td class="r">{{ $money($subtotal) }}</td></tr>
                    @if ($tax)<tr><td class="ink5">{{ $gst['label'] }} ({{ $rate }}%)</td><td class="r">{{ $money($tax) }}</td></tr>@endif
                </table>
                <table style="margin-top:6px"><tr class="grand"><td>Total in {{ $currency }}</td><td class="r">{{ $money($total) }}</td></tr></table>
            </td>
        </tr>
    </table>
</div>

@include('documents.partials.pro-footer')
</body>
</html>
