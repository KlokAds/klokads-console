<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('documents.partials.pro-styles')
</head>
<body>
@php
    $isReceipt = $type === 'receipt';
    $rate = rtrim(rtrim(number_format($gst['rate'], 2), '0'), '.');
    $campaignTotal = collect($campaigns)->sum('amount');
@endphp

<div class="band">
    <table>
        <tr>
            <td>@include('documents.partials.logo', ['name' => $business['name'], 'size' => 26, 'dark' => true])</td>
            <td class="r">
                <div class="eyebrow" style="color:#aaa2ff">{{ $isReceipt ? 'Payment receipt' : ($gst['charged'] ? 'Tax invoice' : 'Invoice') }}</div>
                <div style="font-size:12px;margin-top:4px">{{ $number }}</div>
            </td>
        </tr>
    </table>
    <table style="margin-top:28px">
        <tr>
            <td>
                <div class="eyebrow">{{ $isReceipt ? 'Amount paid' : 'Amount due' }}</div>
                <div style="font-size:32px;font-weight:bold;margin-top:6px">{{ $cash($isReceipt ? $amount_paid : $amount_due) }}</div>
                <div style="margin-top:8px">
                    <span class="pill {{ $isReceipt ? 'pill-paid' : 'pill-due' }}">{{ $isReceipt ? 'Paid' : ($auto_charge ? 'Auto-charge' : 'Due '.$due_at?->format('j M Y')) }}</span>
                    <span style="color:#b6bbcf;margin-left:8px">{{ $service }}{{ $period_start ? ' · '.$period_start->format('j M').' – '.$period_end?->format('j M Y') : '' }}</span>
                </div>
            </td>
            <td class="r" style="width:42%;color:#d9dce8;line-height:1.7">
                <div class="eyebrow">Billed to</div>
                <b style="color:#fff">{{ $client->company ?: $client->name }}</b><br>
                @if ($client->company){{ $client->name }}<br>@endif
                @if (! empty($client->address)){!! nl2br(e($client->address)) !!}<br>@endif
                {{ $client->email }}
                @if (! empty($client->tax_id))<br>Tax ID: {{ $client->tax_id }}@endif
            </td>
        </tr>
    </table>
</div>
<div class="accent"></div>

<div class="wrap">
    <table>
        <tr>
            <td style="width:25%;padding-right:10px"><div class="card"><div class="eyebrow">{{ $isReceipt ? 'Paid on' : 'Issued' }}</div><div class="b" style="margin-top:4px">{{ ($isReceipt ? $paid_at : $issued_at)?->format('j M Y, g:i A') }}</div></div></td>
            <td style="width:25%;padding-right:10px"><div class="card"><div class="eyebrow">{{ $isReceipt ? 'Payment method' : 'Due date' }}</div><div class="b" style="margin-top:4px">{{ $isReceipt ? ($paid_with ?: 'Card') : ($due_at?->format('j M Y') ?: 'On receipt') }}</div></div></td>
            <td style="width:25%;padding-right:10px"><div class="card"><div class="eyebrow">Ad account</div><div class="b" style="margin-top:4px">{{ $ad_account_id ?: '—' }}</div></div></td>
            <td style="width:25%"><div class="card"><div class="eyebrow">{{ $isReceipt ? 'Transaction' : 'Reference' }}</div><div class="b" style="margin-top:4px;font-size:9.5px">{{ $isReceipt ? ($transaction_id ?: $number) : $number }}</div></div></td>
        </tr>
    </table>

    @if (! empty($client_spend))
        @include('documents.partials.client-spend', ['fmt' => $cash, 'margin' => '22px 0 4px', 'radius' => '10px'])
    @elseif ($campaigns)
        <div class="b" style="font-size:13px;margin:24px 0 10px">Campaign breakdown</div>
        <table class="grid">
            <thead><tr><th>Campaign</th><th>Ad set</th><th class="r">Delivery</th><th class="r">Spend</th></tr></thead>
            <tbody>
                @foreach ($campaigns as $c)
                    <tr>
                        <td class="b">{{ $c['name'] }}</td>
                        <td class="ink5">{{ $c['detail'] ?? '—' }}</td>
                        <td class="r ink5">{{ isset($c['quantity']) ? number_format($c['quantity']).' '.($c['unit'] ?? '') : '—' }}</td>
                        <td class="r b">{{ $cash($c['amount']) }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="3" class="r ink5">Campaign total</td><td class="r b">{{ $cash($campaignTotal) }}</td></tr>
            </tbody>
        </table>
    @endif

    <div class="b" style="font-size:13px;margin:24px 0 10px">{{ $isReceipt ? 'Payment summary' : 'Invoice summary' }}</div>
    <table class="grid">
        <thead><tr><th>Description</th><th class="r" style="width:22%">Amount</th></tr></thead>
        <tbody>
            @foreach ($lines as $line)
                <tr><td>{{ $line['description'] }}</td><td class="r">{{ $cash($line['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <table style="margin-top:6px">
        <tr>
            <td style="width:55%;padding-top:8px" class="ink5">
                @if ($isReceipt)
                    Thank you — this payment is complete. Keep this receipt for your records.
                @elseif ($pay_url)
                    Pay securely online:<br><span style="color:#5b45e8">{{ $pay_url }}</span>
                @elseif ($auto_charge)
                    This amount is charged automatically to your saved card.
                @endif
            </td>
            <td>
                <table class="tot">
                    <tr><td class="ink5">Subtotal</td><td class="r">{{ $cash($subtotal) }}</td></tr>
                    @if ($tax)<tr><td class="ink5">{{ $gst['label'] }} ({{ $rate }}%)</td><td class="r">{{ $cash($tax) }}</td></tr>@endif
                </table>
                <table style="margin-top:6px"><tr class="grand"><td>{{ $isReceipt ? 'Total paid' : 'Total' }}</td><td class="r">{{ $cash($total) }}</td></tr></table>
            </td>
        </tr>
    </table>
</div>

@include('documents.partials.pro-footer')
</body>
</html>
