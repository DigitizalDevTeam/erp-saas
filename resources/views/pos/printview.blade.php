{{-- Require (not @include) so $printLayout / $standardPartial stay in this view's scope --}}
@php include resource_path('views/pos/standard/_data.php'); @endphp
<style id="pos-receipt-styles">
    .segunda-via-watermark { position: absolute; left: 0; top: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 10; opacity: 0.12; font-size: 4rem; font-weight: bold; color: #000; transform: rotate(-35deg); white-space: nowrap; }
    .view-qrcode-receipt img { max-width: 70px; height: auto; display: block; }
    .view-qrcode-receipt-svg { line-height: 0; }
    .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; max-width: 100%; max-height: 100%; }
    .pos-print-layout-slip #printarea { max-width: 80mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-a5 #printarea { max-width: 148mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-a4 #printarea,
    .pos-print-layout-receipt #printarea { max-width: 210mm; margin-left: auto; margin-right: auto; }
    @media print {
        body * { visibility: hidden; }
        #printarea, #printarea * { visibility: visible; }
        #printarea { position: absolute; left: 0; top: 0; width: 100%; color: #111 !important; }
        @page pos-slip { size: 80mm auto; margin: 3mm; }
        @page pos-a5 { size: A5 portrait; margin: 8mm; }
        @page pos-a4 { size: A4 portrait; margin: 10mm; }
        @page pos-receipt { margin: 10mm; }
        #printarea.pos-print-layout-slip { page: pos-slip; width: 72mm !important; max-width: 72mm !important; margin: 0 auto !important; }
        #printarea.pos-print-layout-a5 { page: pos-a5; width: 100% !important; max-width: 148mm !important; }
        #printarea.pos-print-layout-a4,
        #printarea.pos-print-layout-receipt { page: pos-a4; width: 100% !important; max-width: 210mm !important; }
        .receipt-copy { page-break-after: always; }
        .receipt-copy:last-child { page-break-after: auto; }
        .segunda-via-watermark { opacity: 0.15; font-size: 3.5rem; }
        .no-print-copies { display: none !important; }
        .view-qrcode-receipt, .view-qrcode-receipt * { visibility: visible !important; }
        .view-qrcode-receipt svg { display: block !important; visibility: visible !important; }
    }
</style>
@if($standalone)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Receipt') }} - {{ $details['pos_id'] ?? '' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/main.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <script src="{{ asset('js/jquery.min.js') }}"></script>
</head>
<body class="pos-print-standalone pos-print-layout-{{ $printLayout }}" style="padding:1rem; background:#f5f5f5;">
@endif
<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : 'modal-body' }} pos-module position-relative pos-print-layout-{{ $printLayout }}" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
    <div class="receipt-copy-wrapper receipt-copy position-relative">
        @if($segundaVia)
            <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
        @endif
        @include($standardPartial)
    </div>
</div>

@if($standalone)
    <div class="mt-3 no-print-copies">
        <button type="button" class="btn-primary" id="print">{{ __('pos.print') }}</button>
    </div>
@else
    <div class="justify-content-center pt-2 modal-footer no-print-copies d-flex flex-wrap align-items-center gap-2">
        <label class="mb-0 d-flex align-items-center gap-1">
            <span class="small">{{ __('pos.number_of_copies') }}:</span>
            <select id="print-copies" class="form-select form-select-sm" style="width: auto;">
                @for($i = 1; $i <= 10; $i++)
                    <option value="{{ $i }}" {{ $i == 1 ? 'selected' : '' }}>{{ $i }}</option>
                @endfor
            </select>
        </label>
        <a href="#" id="print" class="btn btn-primary btn-sm">{{ __('pos.print') }}</a>
        @if(!$segundaVia && $posIdRaw)
            <button id="segunda-via-link" class="btn btn-outline-secondary btn-sm" title="{{ __('pos.segunda_via') }}">{{ __('pos.segunda_via') }}</button>
        @endif
    </div>
@endif
<script>
var posPrintLayout = '{{ $printLayout }}';
var posDirectPrint = {{ $directPrint ? 'true' : 'false' }};
(function() {
    function doPrint(copies) {
        var printarea = document.getElementById("printarea");
        var receipt = printarea ? printarea.querySelector(".receipt-copy") : null;
        if (!receipt) { window.print(); return; }
        copies = Math.max(1, parseInt(copies, 10) || 1);
        var clones = [];
        for (var i = 1; i < copies; i++) {
            var clone = receipt.cloneNode(true);
            printarea.appendChild(clone);
            clones.push(clone);
        }
        if (posDirectPrint) {
            setTimeout(function() {
                window.print();
                setTimeout(function() { clones.forEach(function(c) { if (c && c.parentNode) c.remove(); }); }, 1500);
            }, 150);
            return;
        }
        var html = printarea.innerHTML;
        clones.forEach(function(c) { c.remove(); });
        var receiptStyles = '';
        var styleEl = document.getElementById('pos-receipt-styles');
        if (styleEl && styleEl.innerHTML) receiptStyles = styleEl.innerHTML;
        var printWin = window.open('', '_blank', 'width=900,height=700');
        if (!printWin) { window.print(); return; }
        var layout = (typeof posPrintLayout !== 'undefined' ? posPrintLayout : 'receipt');
        var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>{{ __("Receipt") }}</title>';
        doc += '<link rel="stylesheet" href="{{ asset("assets/css/plugins/main.css") }}">';
        doc += '<link rel="stylesheet" href="{{ asset("assets/css/style.css") }}">';
        doc += '<style>body{background:#fff;margin:0;padding:12px;font-family:Arial,sans-serif;color:#111}';
        if (layout === 'slip') doc += '#printarea{width:80mm;max-width:100%}';
        else if (layout === 'a5') doc += '#printarea{width:100%;max-width:148mm}';
        else doc += '#printarea{width:100%;max-width:210mm}';
        doc += receiptStyles + '</style></head><body class="pos-print-layout-' + layout + '"><div id="printarea" class="pos-print-layout-' + layout + '">' + html + '</div></body></html>';
        printWin.document.write(doc);
        printWin.document.close();
        printWin.focus();
        setTimeout(function() {
            printWin.print();
            setTimeout(function() { try { printWin.close(); } catch (e) {} }, 1500);
        }, 300);
    }
    $(document).off("click","#print").on("click","#print",function(e){
        e.preventDefault();
        e.stopImmediatePropagation();
        var copies = document.getElementById("print-copies") ? document.getElementById("print-copies").value : 1;
        doPrint(copies);
    });
    $(document).on('click','#segunda-via-link',function(){
        $.get('{{url("/")}}/printview/pos', {
            pos_id: '{{ (int)$posIdRaw }}',
            modal: 1,
            segunda_via: 1,
            source: '{{ $printSource }}'
        }, function(html){
            $('#commonModal .modal-title').text('{{ __("Receipt") }}');
            $('#commonModal .body').html(html);
            $('#commonModal').modal('show');
        });
    });
})();
</script>
@if($standalone)
</body>
</html>
@endif
