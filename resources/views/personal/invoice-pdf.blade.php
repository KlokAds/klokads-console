@php
    $bdt = $invoice->currency === 'BDT';
    // DejaVu Sans has the ৳ sign; plain Helvetica would print a box.
    $money = fn (int $c) => ($bdt ? 'BDT ' : $invoice->currency.' ').number_format($c / 100, 2);
    $labels = \App\Support\Catalog::PAY_METHODS;
    $c = $invoice->client;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 48px 52px; }
    body { font-family: 'DejaVu Sans', Helvetica, sans-serif; color: #131729; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .muted { color: #646a85; }
    .r { text-align: right; }
    .items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #646a85; padding: 8px 0; border-bottom: 1px solid #d9dce8; }
    .items td { padding: 9px 0; border-bottom: 1px solid #eceef5; }
    .box { border: 1px solid #e6e8ef; border-radius: 10px; padding: 9px 12px; margin-bottom: 7px; }
    .paid { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr>
        <td>
            <div style="font-size:22px;font-weight:bold">{{ $me['name'] }}</div>
            <div class="muted" style="margin-top:4px;line-height:1.6">
                @if ($me['phone']){{ $me['phone'] }}<br>@endif
                @if ($me['email']){{ $me['email'] }}<br>@endif
                @if ($me['address']){!! nl2br(e($me['address'])) !!}@endif
            </div>
        </td>
        <td class="r">
            <div style="font-size:26px">Invoice</div>
            <div class="muted" style="margin-top:4px">{{ $invoice->number }}</div>
            <div style="margin-top:8px">
                @if ($invoice->status === 'paid')
                    <span class="paid" style="background:#d1fae5;color:#047857">PAID {{ $invoice->paid_on?->format('j M Y') }}</span>
                @elseif ($invoice->status === 'partial')
                    <span class="paid" style="background:#fef3c7;color:#b45309">PART PAID</span>
                @endif
            </div>
        </td>
    </tr>
</table>

<table style="margin-top:28px">
    <tr>
        <td style="width:55%">
            <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.08em">Bill to</div>
            <div style="font-weight:bold;margin-top:4px">{{ $c->company ?: $c->name }}</div>
            <div class="muted" style="line-height:1.6">
                @if ($c->company){{ $c->name }}<br>@endif
                @if ($c->phone){{ $c->phone }}<br>@endif
                @if ($c->address){!! nl2br(e($c->address)) !!}@endif
            </div>
        </td>
        <td class="r" style="line-height:1.8">
            <span class="muted">Issued</span> {{ $invoice->issued_on->format('j M Y') }}<br>
            @if ($invoice->due_on)<span class="muted">Due</span> {{ $invoice->due_on->format('j M Y') }}<br>@endif
        </td>
    </tr>
</table>

<table class="items" style="margin-top:24px">
    <thead><tr><th>Description</th><th class="r" style="width:12%">Qty</th><th class="r" style="width:20%">Rate</th><th class="r" style="width:20%">Amount</th></tr></thead>
    <tbody>
        @foreach ($invoice->items as $i)
            <tr>
                <td>{{ $i['description'] }}</td>
                <td class="r">{{ rtrim(rtrim(number_format((float) $i['qty'], 2), '0'), '.') }}</td>
                <td class="r">{{ $money((int) $i['rate']) }}</td>
                <td class="r">{{ $money((int) round((float) $i['qty'] * (int) $i['rate'])) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top:12px">
    <tr>
        <td style="width:55%"></td>
        <td>
            <table style="line-height:2">
                <tr><td class="muted">Total</td><td class="r" style="font-weight:bold">{{ $money($invoice->total) }}</td></tr>
                @if ($invoice->paid)<tr><td class="muted">Paid</td><td class="r">{{ $money($invoice->paid) }}</td></tr>@endif
                <tr><td style="font-weight:bold;font-size:13px">Amount due</td><td class="r" style="font-weight:bold;font-size:15px">{{ $money($invoice->due()) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($invoice->due() > 0 && ($me['methods'] || $me['qr']))
    <div style="margin-top:26px;font-weight:bold;font-size:12px">How to pay</div>
    <table style="margin-top:8px">
        <tr>
            <td style="width:{{ $me['qr'] ? '62%' : '100%' }};padding-right:14px">
                @foreach ($me['methods'] as $m)
                    <div class="box">
                        <b>{{ $m['label'] ?? ($labels[$m['type']] ?? $m['type']) }}</b>@if (! empty($m['name'])) · {{ $m['name'] }}@endif<br>
                        <span style="font-size:13px">{{ $m['number'] ?? '' }}</span>
                        @if (! empty($m['extra']))<br><span class="muted">{{ $m['extra'] }}</span>@endif
                    </div>
                @endforeach
            </td>
            @if ($me['qr'])
                <td class="r">
                    <img src="{{ $me['qr'] }}" style="width:150px;height:150px">
                    <div class="muted" style="margin-top:4px">Scan with any Bangla QR app</div>
                </td>
            @endif
        </tr>
    </table>
    <div class="muted" style="margin-top:10px">Please use <b>{{ $invoice->number }}</b> as the reference. Pay online: {{ $invoice->publicUrl() }}</div>
@endif

@if ($invoice->note)
    <div class="muted" style="margin-top:22px">{!! nl2br(e($invoice->note)) !!}</div>
@endif
</body>
</html>
