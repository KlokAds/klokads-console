{{-- "Page X of Y" bottom-right on every page (dompdf inline script; templates are ours, never user HTML). --}}
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('Helvetica');
        $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $size = 8.5;
        $width = $fontMetrics->getTextWidth('Page 0 of 0', $font, $size);
        $pdf->page_text($pdf->get_width() - $width - 45, $pdf->get_height() - 34, $text, $font, $size, [{{ implode(',', $color ?? [0.4, 0.4, 0.4]) }}]);
    }
</script>
