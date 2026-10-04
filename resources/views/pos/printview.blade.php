{{-- Require (not @include) so $printLayout / $standardPartial stay in this view's scope --}}
@php include resource_path('views/pos/standard/_data.php'); @endphp
<style id="pos-receipt-styles">
    .receipt-copy { position: relative; overflow: hidden; }
    .segunda-via-watermark {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        pointer-events: none; z-index: 10; opacity: 0.1; font-size: 22px; font-weight: 800;
        color: #000; transform: rotate(-28deg); white-space: nowrap; letter-spacing: 2px;
        overflow: hidden; max-width: 100%;
    }
    #printarea.pos-print-layout-slip .segunda-via-watermark,
    .pos-print-layout-slip .segunda-via-watermark {
        font-size: 18px; opacity: 0.12; letter-spacing: 1px;
    }
    .view-qrcode-receipt img { max-width: 40mm; height: auto; display: block; }
    .view-qrcode-receipt-svg { line-height: 0; }
    .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; max-width: 100%; max-height: 100%; }
    /* Beat theme/Bootstrap img { max-width:100% } so logos stay small in View Bill */
    #commonModal .body img.logo,
    #commonModal .body img.mz-receipt-logo,
    #printarea img.logo,
    #printarea img.mz-receipt-logo,
    #printarea.modal-body img.logo,
    #printarea.modal-body img.mz-receipt-logo,
    #printarea .mz-factura img.logo,
    #printarea .mz-talao img.logo,
    #printarea .mz-talao img.mz-receipt-logo,
    #printarea .mz-talao-uthara img.logo,
    #printarea .mz-talao-uthara img.mz-receipt-logo {
        max-width: 96px !important;
        max-height: 48px !important;
        width: auto !important;
        height: auto !important;
        object-fit: contain !important;
        display: block !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }
    .pos-print-layout-slip #printarea img.logo,
    .pos-print-layout-slip #printarea img.mz-receipt-logo,
    #printarea.pos-print-layout-slip img.logo,
    #printarea.pos-print-layout-slip img.mz-receipt-logo {
        max-width: 96px !important;
        max-height: 44px !important;
    }
    /* Slip width: class is on #printarea itself (modal + print) */
    #printarea.pos-print-layout-slip,
    .pos-print-layout-slip #printarea {
        max-width: 80mm;
        margin-left: auto;
        margin-right: auto;
    }
    /* Narrow receipt modal so the 80mm slip is the focus */
    #commonModal.pos-receipt-modal-slip .modal-dialog {
        max-width: 420px;
    }
    #commonModal.pos-receipt-modal-slip .modal-content {
        margin-left: auto;
        margin-right: auto;
    }
    /* Modal preview: force header/meta centered (theme can override .center) */
    #commonModal .mz-talao .mz-talao-center,
    #commonModal .mz-talao .mz-talao-center .meta-line,
    #commonModal .mz-talao .mz-talao-center .small,
    #commonModal .mz-talao .mz-talao-center .company-name,
    #printarea .mz-talao .mz-talao-center,
    #printarea .mz-talao .mz-talao-center .meta-line,
    #printarea .mz-talao .mz-talao-center .small,
    #printarea .mz-talao .mz-talao-center .company-name {
        text-align: center !important;
    }
    .pos-print-layout-a5 #printarea,
    #printarea.pos-print-layout-a5 { max-width: 148mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-a4 #printarea,
    .pos-print-layout-receipt #printarea,
    #printarea.pos-print-layout-a4,
    #printarea.pos-print-layout-receipt { max-width: 210mm; margin-left: auto; margin-right: auto; }
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
        .segunda-via-watermark { opacity: 0.12; font-size: 16px; letter-spacing: 1px; }
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

@if(empty($agentPrint))
@if($standalone)
    <div class="mt-3 no-print-copies">
        <button type="button" class="btn-primary" id="print"
           data-print-source="{{ $printSource ?? 'retail' }}"
           data-print-agent="{{ !empty($usePrintAgentForInvoice) ? '1' : '0' }}"
           data-pos-id="{{ (int) ($posIdRaw ?? 0) }}"
           data-branch-id="{{ (int) ($receiptBranchId ?? 0) }}">{{ __('pos.print') }}</button>
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
        <a href="#" id="print" class="btn btn-primary btn-sm"
           data-print-source="{{ $printSource ?? 'retail' }}"
           data-print-agent="{{ !empty($usePrintAgentForInvoice) ? '1' : '0' }}"
           data-pos-id="{{ (int) ($posIdRaw ?? 0) }}"
           data-branch-id="{{ (int) ($receiptBranchId ?? 0) }}">{{ __('pos.print') }}</a>
        @if(!$segundaVia && $posIdRaw)
            <button id="segunda-via-link" class="btn btn-outline-secondary btn-sm" title="{{ __('pos.segunda_via') }}">{{ __('pos.segunda_via') }}</button>
        @endif
    </div>
@endif
@if(($printSource ?? 'retail') === 'restaurant')
<script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
@endif
<script>
var posPrintLayout = '{{ $printLayout }}';
var posDirectPrint = {{ $directPrint ? 'true' : 'false' }};
var posPrintSource = '{{ $printSource ?? 'retail' }}';
var posPrintPosId = {{ (int) ($posIdRaw ?? 0) }};
var posUsePrintAgentForInvoice = {{ !empty($usePrintAgentForInvoice) ? 'true' : 'false' }};
var posRestaurantPrintReceiptUrl = '{{ route("pos.restaurant.print-receipt") }}';
(function() {
    // Size the Receipt modal to fit an 80mm slip preview
    try {
        var $modal = $('#commonModal');
        if ($modal.length) {
            $modal.removeClass('pos-receipt-modal-slip pos-receipt-modal-a5 pos-receipt-modal-a4');
            if (posPrintLayout === 'slip' || posPrintLayout === 'receipt') {
                $modal.addClass('pos-receipt-modal-slip');
            } else if (posPrintLayout === 'a5') {
                $modal.addClass('pos-receipt-modal-a5');
            } else {
                $modal.addClass('pos-receipt-modal-a4');
            }
        }
    } catch (e) {}

    function doBrowserPrint(copies) {
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
        var styleChunks = [];
        var styleRoot = printarea.parentElement || document;
        styleRoot.querySelectorAll('style').forEach(function (el) {
            if (el && el.innerHTML) styleChunks.push(el.innerHTML);
        });
        printarea.querySelectorAll('style').forEach(function (el) {
            if (el && el.innerHTML) styleChunks.push(el.innerHTML);
        });
        if (!styleChunks.length) {
            var styleEl = document.getElementById('pos-receipt-styles');
            if (styleEl && styleEl.innerHTML) styleChunks.push(styleEl.innerHTML);
        }
        var receiptStyles = styleChunks.join('\n');
        var layout = (typeof posPrintLayout !== 'undefined' ? posPrintLayout : 'slip');
        if (layout === 'receipt') layout = 'slip';
        var winW = layout === 'slip' ? 420 : (layout === 'a5' ? 620 : 900);
        var printWin = window.open('', '_blank', 'width=' + winW + ',height=700');
        if (!printWin) { window.print(); return; }
        var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>{{ __("Receipt") }}</title>';
        doc += '<style>body{background:#fff;margin:0;padding:12px;font-family:Arial,Helvetica,sans-serif;color:#111}';
        if (layout === 'slip') {
            doc += '#printarea{width:80mm;max-width:80mm;margin:0 auto}';
            doc += '@page{size:80mm auto;margin:3mm}';
        } else if (layout === 'a5') {
            doc += '#printarea{width:100%;max-width:148mm;margin:0 auto}';
            doc += '@page{size:A5 portrait;margin:8mm}';
        } else {
            doc += '#printarea{width:100%;max-width:210mm;margin:0 auto}';
            doc += '@page{size:A4 portrait;margin:10mm}';
        }
        doc += receiptStyles + '</style></head><body class="pos-print-layout-' + layout + '"><div id="printarea" class="pos-print-layout-' + layout + '">' + html + '</div></body></html>';
        printWin.document.write(doc);
        printWin.document.close();
        printWin.focus();
        setTimeout(function() {
            printWin.print();
            setTimeout(function() { try { printWin.close(); } catch (e) {} }, 1500);
        }, 300);
    }

    function sendToPrinterAgent() {
        var $printBtn = $('#print');
        var branchId = $('#pos-restaurant-branch').val()
            || $('#pos_branch_id_hidden').val()
            || ($printBtn.attr('data-branch-id') || '')
            || '{{ (int) ($receiptBranchId ?? 0) }}'
            || '';
        var posId = posPrintPosId
            || ($printBtn.attr('data-pos-id') || '')
            || $('#pos_restaurant_pos_id').val()
            || '';
        return $.ajax({
            url: posRestaurantPrintReceiptUrl,
            type: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}',
                branch_id: branchId,
                pos_id: posId,
                pos_table_id: $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val() || '',
                pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                pos_order_type: $('#pos-restaurant-order-type').val() || $('#pos_order_type_hidden').val() || ''
            }
        });
    }

    function tryAgentReceiptPrint() {
        var $btn = $('#print');
        $btn.addClass('disabled').css('pointer-events', 'none');
        sendToPrinterAgent().done(function (res) {
            if (res && res.success) {
                if (typeof show_toastr === 'function') {
                    show_toastr('{{ __("Success") }}', (res.message || '{{ __("Sent to printer agent.") }}'), 'success');
                }
                if (res.saved_as_image && res.image_url) {
                    window.open(res.image_url, '_blank');
                }
                return;
            }
            if (res && res.fallback) {
                doBrowserPrint(document.getElementById("print-copies") ? document.getElementById("print-copies").value : 1);
                return;
            }
            if (typeof show_toastr === 'function') {
                show_toastr('{{ __("Error") }}', (res && res.error) ? res.error : '{{ __("pos.error") }}', 'error');
            }
        }).fail(function (xhr) {
            var json = xhr.responseJSON || {};
            if (json.fallback) {
                doBrowserPrint(document.getElementById("print-copies") ? document.getElementById("print-copies").value : 1);
                return;
            }
            var msg = json.error ? json.error : '{{ __("Could not send to printer agent.") }}';
            if (typeof show_toastr === 'function') {
                show_toastr('{{ __("Error") }}', msg, 'error');
            }
        }).always(function () {
            $btn.removeClass('disabled').css('pointer-events', '');
        });
    }

    function doPrint(copies) {
        var $printBtn = $('#print');
        var useAgent = (posPrintSource === 'restaurant' && posUsePrintAgentForInvoice)
            || ($printBtn.attr('data-print-source') === 'restaurant' && $printBtn.attr('data-print-agent') === '1');
        if (useAgent) {
            tryAgentReceiptPrint();
            return;
        }
        doBrowserPrint(copies);
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
@endif
@if(!empty($standalone) && empty($agentPrint))
</body>
</html>
@endif
