{{-- Commission-only invoices: the ad spend the client paid the platform directly, shown for the record only. --}}
@php($fmt = $fmt ?? $money)
<div style="margin:{{ $margin ?? '20px 0 6px' }};border:1px solid #d8dbe6;border-radius:{{ $radius ?? '0' }};padding:12px 14px;background:#f7f8fb">
    <table style="width:100%">
        <tr>
            <td style="vertical-align:top">
                <div style="font-weight:bold">{{ $service }} spend{{ $period_start && $period_end ? ', '.$period_start->format('M j').' – '.$period_end->format('M j, Y') : '' }}</div>
                <div style="color:#6b7186;margin-top:2px;font-size:10.5px">Paid directly by you to the ad platform — shown for reference, not charged on this {{ $type === 'receipt' ? 'receipt' : 'invoice' }}.</div>
            </td>
            <td style="text-align:right;vertical-align:top;font-weight:bold;white-space:nowrap">{{ $fmt($client_spend) }}</td>
        </tr>
        @foreach ($campaigns as $c)
            <tr>
                <td style="padding-top:6px;color:#3d4256">{{ $c['name'] }}@if (! empty($c['detail'])) <span style="color:#8a90a6">· {{ $c['detail'] }}</span>@endif @if (isset($c['quantity']))<span style="color:#8a90a6"> · {{ number_format($c['quantity']) }} {{ $c['unit'] ?? '' }}</span>@endif</td>
                <td style="padding-top:6px;text-align:right;color:#3d4256;white-space:nowrap">{{ $fmt($c['amount']) }}</td>
            </tr>
        @endforeach
    </table>
</div>
