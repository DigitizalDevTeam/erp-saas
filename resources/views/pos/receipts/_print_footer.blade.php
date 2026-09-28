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
    function doBrowserPrint(copies) {
        var printarea = document.getElementById("printarea");
        var receipt = printarea ? printarea.querySelector(".receipt-copy") : null;
        if (!receipt) {
            window.print();
            return;
        }
        copies = Math.max(1, parseInt(copies, 10) || 1);
        var clones = [];
        for (var i = 1; i < copies; i++) {
            var clone = receipt.cloneNode(true);
            printarea.appendChild(clone);
            clones.push(clone);
        }
        if (posDirectPrint) {
            function removeClones() {
                clones.forEach(function(c) { if (c && c.parentNode) c.remove(); });
            }
            setTimeout(function() {
                window.print();
                setTimeout(removeClones, 1500);
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
        if (!styleChunks.length) {
            var styleEl = document.getElementById('pos-receipt-styles');
            if (styleEl && styleEl.innerHTML) styleChunks.push(styleEl.innerHTML);
        }
        var receiptStyles = styleChunks.join('\n');
        var layout = (typeof posPrintLayout !== 'undefined' ? posPrintLayout : 'slip');
        if (layout === 'receipt') layout = 'slip';
        var winW = layout === 'slip' ? 420 : (layout === 'a5' ? 620 : 800);
        var printWin = window.open('', '_blank', 'width=' + winW + ',height=700');
        if (!printWin) {
            window.print();
            return;
        }
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

    function captureReceiptImageBase64() {
        return new Promise(function (resolve) {
            var el = document.querySelector('#printarea .receipt-copy') || document.getElementById('printarea');
            if (!el) {
                resolve('');
                return;
            }
            if (typeof html2pdf === 'undefined') {
                resolve('');
                return;
            }
            try {
                html2pdf().set({
                    margin: 0,
                    html2canvas: { scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', logging: false }
                }).from(el).outputImg('datauristring').then(function (dataUri) {
                    resolve(dataUri || '');
                }).catch(function () {
                    resolve('');
                });
            } catch (e) {
                resolve('');
            }
        });
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
        var data = {
            _token: $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}',
            branch_id: branchId,
            pos_id: posId,
            pos_table_id: $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val() || '',
            pos_shift_id: $('#pos_shift_id_hidden').val() || '',
            pos_order_type: $('#pos-restaurant-order-type').val() || $('#pos_order_type_hidden').val() || ''
        };
        return $.ajax({
            url: posRestaurantPrintReceiptUrl,
            type: 'POST',
            data: data
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
        var pos_id = '{{ (int)$posIdRaw }}';
        $('#commonModal .modal-dialog').addClass('modal-sm');
        $.get('{{url("/")}}/printview/pos', {
            pos_id: pos_id,
            modal: 1,
            segunda_via: 1,
            source: '{{ $printSource }}'
        }, function(html){
            $('#commonModal .modal-title').text('POS Receipt');
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
