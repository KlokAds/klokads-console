<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $kind === 'receipt' ? 'Receipt' : 'Invoice' }} {{ $d['number'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6fa;font-family:Helvetica,Arial,sans-serif;color:#131729">
@php $isReceipt = $kind === 'receipt'; $clientName = $d['client']->name ?? ''; @endphp
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;padding:32px 12px">
    <tr><td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
            <tr><td style="padding:0 4px 18px">
                <table cellpadding="0" cellspacing="0"><tr>
                    <td style="width:30px;height:30px;background:#5b45e8;border-radius:8px;color:#fff;font-weight:bold;font-size:17px;text-align:center;line-height:30px">K</td>
                    <td style="padding-left:9px;font-weight:bold;font-size:18px;color:#131729">{{ $d['business']['name'] }}</td>
                </tr></table>
            </td></tr>

            <tr><td style="background:#ffffff;border:1px solid #e6e8ef;border-radius:16px;overflow:hidden">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr><td style="background:#0b1020;padding:28px 30px;color:#ffffff;border-radius:16px 16px 0 0">
                        <div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#aaa2ff;font-weight:bold">{{ $isReceipt ? 'Payment received' : 'New invoice' }} · {{ $d['number'] }}</div>
                        <div style="font-size:34px;font-weight:bold;margin-top:8px">{{ $amount }}</div>
                        <div style="margin-top:6px;color:#b6bbcf;font-size:13px">
                            @if ($isReceipt)
                                Paid {{ $d['paid_at']?->format('j M Y') }}{{ $d['paid_with'] ? ' · '.$d['paid_with'] : '' }}
                            @elseif ($d['auto_charge'])
                                Will be charged to your saved card
                            @else
                                Due {{ $d['due_at']?->format('j M Y') ?: 'on receipt' }}
                            @endif
                        </div>
                    </td></tr>
                    <tr><td style="height:4px;background:#5b45e8"></td></tr>

                    <tr><td style="padding:26px 30px 8px;font-size:15px;line-height:1.6">
                        Hi {{ $clientName }},<br>
                        @if ($isReceipt)
                            Thank you — we've received your payment for <b>{{ $d['service'] }}</b>. Your receipt is attached as a PDF.
                        @else
                            Here is your invoice for <b>{{ $d['service'] }}</b>{{ $d['period_start'] ? ' ('.$d['period_start']->format('j M').' – '.$d['period_end']?->format('j M Y').')' : '' }}. The PDF is attached.
                        @endif
                    </td></tr>

                    <tr><td style="padding:10px 30px">
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
                            @foreach ($d['lines'] as $line)
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#353a52">{{ $line['description'] }}</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right;white-space:nowrap">{{ $d['currency'] }} {{ number_format($line['amount'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                            @if ($d['tax'])
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#353a52">{{ $d['gst']['label'] }}</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right">{{ $d['currency'] }} {{ number_format($d['tax'] / 100, 2) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:10px 0;font-weight:bold">Total</td>
                                <td style="padding:10px 0;text-align:right;font-weight:bold">{{ $d['currency'] }} {{ number_format($d['total'] / 100, 2) }}</td>
                            </tr>
                        </table>
                    </td></tr>

                    <tr><td align="center" style="padding:18px 30px 30px">
                        <a href="{{ $url }}" style="display:inline-block;background:#5b45e8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:14px 28px;border-radius:12px">
                            {{ $isReceipt ? 'View receipt' : ($d['auto_charge'] ? 'View invoice' : 'View & pay invoice') }}
                        </a>
                    </td></tr>
                </table>
            </td></tr>

            <tr><td style="padding:18px 6px;font-size:12px;color:#8b91aa;line-height:1.6;text-align:center">
                {{ $d['business']['legal_name'] }}@if ($d['business']['uen']) · UEN {{ $d['business']['uen'] }}@endif
                @if ($d['business']['address'])<br>{{ preg_replace('/\s*\n\s*/', ', ', trim($d['business']['address'])) }}@endif
                @if ($d['business']['email'])<br>Questions? Just reply to this email.@endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
