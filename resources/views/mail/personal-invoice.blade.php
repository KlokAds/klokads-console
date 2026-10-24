@php
    $money = fn (int $c) => ($invoice->currency === 'BDT' ? '৳' : $invoice->currency.' ').number_format($c / 100, $invoice->currency === 'BDT' ? 0 : 2);
    $labels = \App\Support\Catalog::PAY_METHODS;
@endphp
<!DOCTYPE html>
<html>
<body style="margin:0;background:#f5f6fa;font-family:Helvetica,Arial,sans-serif;color:#131729">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
    <tr><td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e6e8ef">
            <tr><td style="background:#0b1020;color:#fff;padding:24px 28px">
                <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#aaa2ff">Invoice {{ $invoice->number }}</div>
                <div style="font-size:30px;font-weight:bold;margin-top:6px">{{ $money($invoice->due()) }}</div>
                <div style="font-size:13px;color:#b6bbcf;margin-top:4px">from {{ $me['name'] }}@if ($invoice->due_on) · due {{ $invoice->due_on->format('j M Y') }}@endif</div>
            </td></tr>
            <tr><td style="padding:22px 28px;font-size:14px;line-height:1.6">
                <p style="margin:0 0 14px">Hi {{ $invoice->client->name }},</p>
                <p style="margin:0 0 18px">Here is invoice {{ $invoice->number }}. You can pay by any of the methods below — after paying, tap <b>“I've paid”</b> on the invoice page so I can confirm it.</p>
                <p style="margin:0 0 22px"><a href="{{ $url }}" style="display:inline-block;background:#5b45e8;color:#fff;text-decoration:none;padding:12px 22px;border-radius:12px;font-weight:bold">View &amp; pay</a></p>
                @foreach ($me['methods'] as $m)
                    <div style="border:1px solid #e6e8ef;border-radius:12px;padding:10px 14px;margin-bottom:8px">
                        <b>{{ $m['label'] ?? ($labels[$m['type']] ?? $m['type']) }}</b><br>
                        @if (! empty($m['name'])){{ $m['name'] }}<br>@endif
                        <span style="font-family:monospace;font-size:15px">{{ $m['number'] ?? '' }}</span>
                        @if (! empty($m['extra']))<br><span style="color:#646a85;font-size:12px">{{ $m['extra'] }}</span>@endif
                    </div>
                @endforeach
                <p style="margin:18px 0 0;color:#646a85;font-size:12px">The invoice PDF is attached. Questions? Just reply to this email.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
