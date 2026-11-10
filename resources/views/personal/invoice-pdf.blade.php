@php
    $bdt = $invoice->currency === 'BDT';
    // DejaVu has the ৳ sign; plain Helvetica would print a box.
    $money = fn (int $c) => ($bdt ? 'BDT ' : $invoice->currency.' ').number_format($c / 100, 2);
    $labels = \App\Support\Catalog::PAY_METHODS;
    $c = $invoice->client;
    $open = in_array($invoice->status, ['unpaid', 'partial'], true);
    $monogram = collect(preg_split('/\s+/', trim($me['name'])))->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
    // A private letterhead: ivory paper, navy ink, a brass line.
    $navy = '#0E1630'; $brass = '#B08D57'; $paper = '#FBF8F2'; $band = '#F4EEE2'; $line = '#E4DCCB'; $muted = '#6B6F82'; $soft = '#8D8A80';
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    /* The bottom 78pt of every page belongs to the sign-off, so lines never run under it. */
    @page { margin: 34px 0 64px 0; }
    @page :first { margin-top: 0; }
    html { background: {{ $paper }}; }
    body { margin: 0; font-family: 'DejaVu Sans', Helvetica, sans-serif; color: {{ $navy }}; font-size: 10.5px; background: {{ $paper }}; }
    .serif { font-family: 'DejaVu Serif', Georgia, serif; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .muted { color: {{ $muted }}; }
    .soft { color: {{ $soft }}; }
    .r { text-align: right; }
    .cap { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; color: {{ $soft }}; font-weight: bold; }
    .wrap { padding: 0 52px; }
    .items th { font-size: 8px; text-transform: uppercase; letter-spacing: 1.6px; color: {{ $soft }}; padding: 0 0 7px; border-bottom: 1.2px solid {{ $navy }}; text-align: left; }
    .items td { padding: 9px 0; border-bottom: 0.6px solid {{ $line }}; }
    .pill { display: inline-block; padding: 4px 11px; border-radius: 99px; font-size: 9px; font-weight: bold; letter-spacing: .5px; }
    .method { border: 0.8px solid {{ $line }}; border-radius: 10px; padding: 9px 12px; background: #FFFDF9; }
</style>
</head>
<body>
{{-- Brass line --}}
<div style="height:6px;background:{{ $navy }}"></div>
<div style="height:2px;background:{{ $brass }}"></div>

{{-- Letterhead --}}
<div class="wrap" style="padding-top:34px;padding-bottom:26px">
    <table>
        <tr>
            <td style="width:52px">
                <div class="serif" style="width:42px;height:29px;padding-top:13px;line-height:1;border-radius:21px;background:{{ $navy }};color:#E9D7B3;text-align:center;font-size:14px;letter-spacing:1px;border:2px solid {{ $brass }}">{{ $monogram }}</div>
            </td>
            <td style="padding-left:6px">
                <div class="serif" style="font-size:20px;font-weight:bold">{{ $me['name'] }}</div>
                <div class="muted" style="margin-top:3px;line-height:1.5">
                    {{ collect([$me['phone'], $me['email']])->filter()->implode('  ·  ') }}
                    @if ($me['address'])<br>{{ str_replace("\n", ', ', $me['address']) }}@endif
                </div>
            </td>
            <td class="r" style="width:40%">
                <div style="font-size:9px;letter-spacing:5px;color:{{ $brass }};font-weight:bold">INVOICE</div>
                <div style="margin-top:5px;font-size:13px;font-weight:bold">{{ $invoice->number }}</div>
                <div style="margin-top:8px">
                    @if ($invoice->status === 'paid')
                        <span class="pill" style="background:#E6F2EC;color:#1E6B4A">PAID · {{ $invoice->paid_on?->format('j M Y') }}</span>
                    @elseif ($invoice->status === 'partial')
                        <span class="pill" style="background:#FBF0DC;color:#8A5A12">PART PAID</span>
                    @elseif ($invoice->status === 'void')
                        <span class="pill" style="background:#EEEDEA;color:#6B6A66">CANCELLED</span>
                    @else
                        <span class="pill" style="background:#FBF0DC;color:#8A5A12">DUE</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- Parties, dates and the amount --}}
<div style="background:{{ $band }};border-top:0.6px solid {{ $line }};border-bottom:0.6px solid {{ $line }}">
    <div class="wrap" style="padding-top:20px;padding-bottom:20px">
        <table>
            <tr>
                <td style="width:34%">
                    <div class="cap">Billed to</div>
                    <div style="margin-top:6px;font-size:12px;font-weight:bold">{{ $c->company ?: $c->name }}</div>
                    <div class="muted" style="line-height:1.5">
                        @if ($c->company && $c->name !== $c->company){{ $c->name }}<br>@endif
                        @if ($c->phone){{ $c->phone }}<br>@endif
                        @if ($c->address){{ str_replace("\n", ', ', $c->address) }}@endif
                    </div>
                </td>
                <td style="width:16%">
                    <div class="cap">Issued</div>
                    <div style="margin-top:6px;font-weight:bold">{{ $invoice->issued_on->format('j M Y') }}</div>
                </td>
                <td class="r">
                    <div class="cap">{{ $open ? 'Amount due' : 'Total' }}</div>
                    <div class="serif" style="margin-top:4px;font-size:24px;font-weight:bold">{{ $money($open ? $invoice->due() : $invoice->grand()) }}</div>
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- Previous dues, then this invoice --}}
@php
    $prev = $invoice->previous()->get();
    // Every line once, as "1 ×": Qty and Rate would only repeat the amount, so show the period
    // ("3 Nov – 9 Nov 2026", after the " — ") in its own column instead. Real quantities keep Qty and Rate.
    $lines = collect($invoice->items ?? [])->merge($prev->flatMap(fn ($x) => $x->items ?? []));
    $simple = $lines->every(fn ($i) => (float) $i['qty'] == 1);
    $part = fn ($d) => array_pad(explode(' — ', (string) $d, 2), 2, '');
    $period = $simple && $lines->contains(fn ($i) => $part($i['description'])[1] !== '');
    $span = $simple ? ($period ? 2 : 1) : 3; // columns before Amount
    $head = '<thead><tr><th>Description</th>'
        .($simple ? ($period ? '<th style="width:30%">Period</th>' : '') : '<th class="r" style="width:9%;text-align:right">Qty</th><th class="r" style="width:20%;text-align:right">Rate</th>')
        .'<th class="r" style="width:20%;text-align:right">Amount</th></tr></thead>';
    $row = function (array $i) use ($simple, $period, $part, $qty, $money) {
        $amount = '<td class="r" style="font-weight:bold">'.e($money((int) round((float) $i['qty'] * (int) $i['rate']))).'</td>';
        if (! $simple) {
            return '<tr><td style="font-size:11px">'.e($i['description']).'</td><td class="r muted">'.e($qty($i['qty'])).'</td><td class="r muted">'.e($money((int) $i['rate'])).'</td>'.$amount.'</tr>';
        }
        [$what, $when] = $part($i['description']);

        return $period
            ? '<tr><td style="font-size:11px;padding-right:12px">'.e($what).'</td><td class="muted" style="font-size:10.5px">'.e($when).'</td>'.$amount.'</tr>'
            : '<tr><td style="font-size:11px">'.e($i['description']).'</td>'.$amount.'</tr>';
    };
@endphp
<div class="wrap" style="padding-top:26px">
    @if ($prev->isNotEmpty())
        <div style="font-size:8px;letter-spacing:1.6px;color:{{ $soft }};text-transform:uppercase;margin-bottom:6px">Previous dues</div>
        <table class="items" style="margin-bottom:16px">
            {!! $head !!}
            <tbody>
                @foreach ($prev as $p)
                    <tr><td colspan="{{ $span }}" style="font-size:11px;background:{{ $band }};padding:7px 8px"><b>{{ $p->number }}</b> <span class="muted">· {{ $p->issued_on->format('j M Y') }}</span></td><td class="r" style="font-weight:bold;background:{{ $band }};padding:7px 8px">{{ $money($p->carried_amount) }}</td></tr>
                    @foreach ($p->items ?? [] as $i)
                        {!! $row($i) !!}
                    @endforeach
                    @if ($p->carried > 0)
                        <tr><td colspan="{{ $span }}" style="font-size:11px">Earlier dues on it</td><td class="r" style="font-weight:bold">{{ $money((int) $p->carried) }}</td></tr>
                    @endif
                    @if ($p->paid > 0)
                        <tr><td colspan="{{ $span }}" style="font-size:11px;color:#1E6B4A">Already paid</td><td class="r" style="font-weight:bold;color:#1E6B4A">−{{ $money((int) $p->paid) }}</td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        <div style="font-size:8px;letter-spacing:1.6px;color:{{ $soft }};text-transform:uppercase;margin-bottom:6px">This invoice</div>
    @endif
    <table class="items" style="margin-top:0">
        {!! $head !!}
        <tbody>
            @foreach ($invoice->items as $i)
                {!! $row($i) !!}
            @endforeach
        </tbody>
    </table>

    <table style="margin-top:14px">
        <tr>
            <td style="width:56%"></td>
            <td>
                <table>
                    <tr><td class="muted" style="padding:3px 0">{{ $prev->isNotEmpty() ? 'This invoice' : 'Total' }}</td><td class="r" style="padding:3px 0">{{ $money($invoice->total) }}</td></tr>
                    @if ($prev->isNotEmpty())
                        <tr><td class="muted" style="padding:3px 0">Previous dues</td><td class="r" style="padding:3px 0">{{ $money($invoice->carried) }}</td></tr>
                        <tr><td style="padding:3px 0;font-weight:bold">Total</td><td class="r" style="padding:3px 0;font-weight:bold">{{ $money($invoice->grand()) }}</td></tr>
                    @endif
                    @if ($invoice->paid)<tr><td style="padding:3px 0;color:#1E6B4A">Paid</td><td class="r" style="padding:3px 0;color:#1E6B4A">− {{ $money($invoice->paid) }}</td></tr>@endif
                    <tr>
                        <td style="padding-top:9px;border-top:1.2px solid {{ $navy }};font-size:8.5px;font-weight:bold;letter-spacing:1.6px">AMOUNT DUE</td>
                        <td class="r serif" style="padding-top:7px;border-top:1.2px solid {{ $navy }};font-size:15px;font-weight:bold">{{ $money($invoice->due()) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{-- How to pay --}}
@if ($open)
    <div class="wrap" style="padding-top:24px;page-break-inside:avoid">
        <table><tr><td style="width:22px;padding-top:7px"><div style="height:1px;background:{{ $brass }}"></div></td><td class="serif" style="padding-left:8px;font-size:13px;font-weight:bold">How to pay</td></tr></table>
        <table style="margin-top:10px">
            <tr>
                <td style="width:{{ $me['qr'] ? '64%' : '100%' }};padding-right:{{ $me['qr'] ? '14px' : '0' }}">
                    {{-- Two to a row: bank name, the number big, then whose and which branch --}}
                    @forelse (array_chunk($me['methods'], 2) as $pair)
                        <table style="margin-bottom:7px"><tr>
                            @foreach ($pair as $m)
                                <td style="width:50%;padding-right:{{ $loop->first ? '6px' : '0' }};padding-left:{{ $loop->first ? '0' : '6px' }};vertical-align:top">
                                    <div class="method">
                                        <div class="cap" style="letter-spacing:1.4px;color:{{ $brass }}">{{ $m['label'] ?? ($labels[$m['type']] ?? $m['type']) }}</div>
                                        <div style="margin-top:3px;font-size:12px;font-weight:bold;font-family:'DejaVu Sans Mono', monospace">{{ $m['number'] ?? '' }}</div>
                                        @if (! empty($m['name']))<div style="margin-top:2px;font-size:9.5px">{{ $m['name'] }}</div>@endif
                                        @if (! empty($m['extra']))<div class="muted" style="margin-top:1px">{{ $m['extra'] }}</div>@endif
                                    </div>
                                </td>
                            @endforeach
                            @if (count($pair) === 1)<td style="width:50%"></td>@endif
                        </tr></table>
                    @empty
                        <div class="muted">Pay {{ $me['name'] }} by your usual way{{ $me['phone'] ? ' — or call '.$me['phone'] : '' }}.</div>
                    @endforelse
                </td>
                @if ($me['qr'])
                    <td class="r">
                        <img src="{{ $me['qr'] }}" style="width:130px;height:130px">
                        <div class="muted" style="margin-top:4px">Scan with any Bangla QR app</div>
                    </td>
                @endif
            </tr>
        </table>
        {{-- The online page as a short, tappable link — never the long address --}}
        <table style="margin-top:10px">
            <tr>
                <td class="muted" style="vertical-align:middle">Please write <b style="color:{{ $navy }}">{{ $invoice->number }}</b> as the reference.</td>
                <td class="r" style="vertical-align:middle;white-space:nowrap">
                    <a href="{{ $invoice->publicUrl() }}" style="color:{{ $navy }};text-decoration:none;font-weight:bold;border:0.8px solid {{ $brass }};border-radius:10px;padding:4px 11px">View &amp; pay online &rarr;</a>
                    <div class="muted" style="margin-top:6px;font-size:8.5px"><a href="{{ $invoice->publicUrl() }}" style="color:{{ $muted }};text-decoration:none">{{ parse_url($invoice->publicUrl(), PHP_URL_HOST) }}/pi/{{ \Illuminate\Support\Str::limit(basename($invoice->publicUrl()), 6, '…') }}</a></div>
                </td>
            </tr>
        </table>
    </div>
@endif

@if ($invoice->note)
    <div class="wrap muted" style="padding-top:20px;line-height:1.6">{!! nl2br(e($invoice->note)) !!}</div>
@endif

{{-- The top margin of a continued page, in the paper colour --}}
<div style="position:fixed;top:-34px;left:0;right:0;height:34px;background:{{ $paper }}"></div>

{{-- Sign-off, at the foot of the page --}}
<div style="position:fixed;bottom:-64px;left:0;right:0;background:{{ $paper }}">
    <div class="wrap" style="padding-bottom:20px">
        <table style="border-top:0.6px solid {{ $line }}">
            <tr>
                <td style="padding-top:12px"><span class="serif" style="font-size:13px;font-style:italic">Thank you.</span> <span class="muted">&nbsp;{{ $me['name'] }}</span></td>
                <td class="r" style="padding-top:14px;font-size:8px;letter-spacing:3px;color:{{ $brass }};font-weight:bold">{{ $invoice->number }}</td>
            </tr>
        </table>
    </div>
    <div style="height:2px;background:{{ $brass }}"></div>
    <div style="height:6px;background:{{ $navy }}"></div>
</div>
</body>
</html>
