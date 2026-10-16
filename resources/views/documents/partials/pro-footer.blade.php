<div class="foot">
    <table>
        <tr>
            <td>
                <b style="color:#646a85">{{ $business['legal_name'] }}</b>
                @if ($business['uen']) · UEN {{ $business['uen'] }}@endif
                @if ($gst['charged'] && $gst['reg_no']) · {{ $gst['label'] }} Reg No {{ $gst['reg_no'] }}@endif
                @if ($business['address'])<br>{{ preg_replace('/\s*\n\s*/', ', ', trim($business['address'])) }}@endif
            </td>
            <td class="r" style="width:35%">
                {{ collect([$business['email'], $business['phone'], $business['website']])->filter()->implode(' · ') }}
            </td>
        </tr>
    </table>
</div>
