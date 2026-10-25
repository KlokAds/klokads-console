<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ad statement — {{ $period }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6fa;font-family:Helvetica,Arial,sans-serif;color:#131729">
@php $m = fn ($cents) => $d['currency'].' '.number_format($cents / 100, 2); @endphp
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
                        <div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#aaa2ff;font-weight:bold">Ad statement · {{ $period }}</div>
                        <div style="font-size:34px;font-weight:bold;margin-top:8px">{{ $m($d['closing']) }}</div>
                        <div style="margin-top:6px;color:#b6bbcf;font-size:13px">Your ad balance at the end of the period</div>
                    </td></tr>
                    <tr><td style="height:4px;background:#5b45e8"></td></tr>

                    <tr><td style="padding:26px 30px 8px;font-size:15px;line-height:1.6">
                        Hi {{ $d['client']['name'] }},<br>
                        Here is how your ad balance was used in {{ $period }}: what each Meta campaign spent, the payments added, and what is left.
                    </td></tr>

                    <tr><td style="padding:10px 30px">
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
                            <tr>
                                <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#6b7089">Opening balance</td>
                                <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right;white-space:nowrap">{{ $m($d['opening']) }}</td>
                            </tr>
                            @foreach ($d['top_ups'] as $t)
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#353a52">+ {{ $t['note'] }} · {{ \Carbon\Carbon::parse($t['date'])->format('j M') }}</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right;white-space:nowrap;color:#059669">+{{ $m($t['amount']) }}</td>
                                </tr>
                            @endforeach
                            @foreach ($lines as $line)
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#353a52">
                                        − {{ $line['label'] }}
                                        @if ($line['detail'])<div style="font-size:12px;color:#8a8fa6">{{ $line['detail'] }}</div>@endif
                                    </td>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right;white-space:nowrap">−{{ $m($line['amount']) }}</td>
                                </tr>
                            @endforeach
                            @foreach ($d['adjustments'] as $a)
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;color:#353a52">− {{ $a['note'] }}</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #eceef5;text-align:right;white-space:nowrap">−{{ $m(abs($a['amount'])) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:12px 0;font-weight:bold">Closing balance</td>
                                <td style="padding:12px 0;text-align:right;font-weight:bold;white-space:nowrap">{{ $m($d['closing']) }}</td>
                            </tr>
                        </table>
                    </td></tr>

                    <tr><td style="padding:8px 30px 28px;font-size:13px;color:#6b7089;line-height:1.6">
                        Spend figures come from Meta for the days of this period. When the balance runs low, your saved card is topped up and you receive the invoice.
                        @if ($d['business']['email'])
                            Questions? Just reply to this email.
                        @endif
                    </td></tr>
                </table>
            </td></tr>

            <tr><td style="padding:18px 4px;text-align:center;font-size:12px;color:#8a8fa6">{{ $d['business']['name'] }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
