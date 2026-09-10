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
        // Collect ALL receipt <style> blocks (shared + layout-specific e.g. compact3),
        // not only #pos-receipt-styles — otherwise header centering/layout CSS is lost in print.
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
        var printWin = window.open('', '_blank', 'width=800,height=600');
        if (!printWin) {
            window.print();
            return;
        }
        var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>{{ __("Receipt") }}</title>';
        var layout = (typeof posPrintLayout !== 'undefined' ? posPrintLayout : 'receipt');
        doc += '<style>body{background:#fff;margin:0;padding:12px;font-family:Arial,Helvetica,sans-serif;color:#111}';
        if (layout === 'slip') {
            doc += '#printarea{width:80mm;max-width:100%;margin:0 auto}';
        } else if (layout === 'a5') {
            doc += '#printarea{width:100%;max-width:148mm;margin:0 auto}';
        } else {
            doc += '#printarea{width:100%;max-width:210mm;margin:0 auto}';
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
@if($standalone)
</body>
</html>
@endif
