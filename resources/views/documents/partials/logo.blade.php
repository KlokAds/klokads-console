{{-- Klok Ads mark + wordmark, drawn in HTML so it renders crisply in PDFs. --}}
<table cellpadding="0" cellspacing="0" style="display:inline-table;border-collapse:collapse"><tr>
    <td style="width:{{ $size ?? 26 }}px;height:{{ $size ?? 26 }}px;background:{{ $dark ?? false ? '#ffffff' : '#5b45e8' }};border-radius:6px;text-align:center;vertical-align:middle;color:{{ $dark ?? false ? '#5b45e8' : '#ffffff' }};font-weight:bold;font-size:{{ round(($size ?? 26) * 0.6) }}px;line-height:{{ $size ?? 26 }}px">K</td>
    <td style="padding-left:7px;vertical-align:middle;font-weight:bold;font-size:{{ round(($size ?? 26) * 0.72) }}px;color:{{ $dark ?? false ? '#ffffff' : '#131729' }};letter-spacing:-0.3px">{{ $name }}</td>
</tr></table>
