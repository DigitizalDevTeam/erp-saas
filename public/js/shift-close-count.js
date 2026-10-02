window.initShiftCloseCount = function (root) {
    if (!root || root.getAttribute('data-bound') === '1') return;
    root.setAttribute('data-bound', '1');

    var expected = parseFloat(root.getAttribute('data-expected')) || 0;
    var symbol = root.getAttribute('data-symbol') || '';
    var symbolPosition = root.getAttribute('data-symbol-position') || 'pre';
    var counted = root.querySelector('.shift-close-counted');
    var difference = root.querySelector('.shift-close-difference');
    var reason = root.querySelector('.shift-close-reason');
    var star = root.querySelector('.shift-close-reason-star');
    var hint = root.querySelector('.shift-close-reason-hint');
    var form = root.closest('form');

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;');
    }

    function formatMoney(amount) {
        var negative = amount < 0;
        var parts = Math.abs(amount).toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var number = (negative ? '-' : '') + parts[0] + ',' + parts[1];
        if (!symbol) return number;
        return symbolPosition === 'post' ? (number + ' ' + symbol) : (symbol + ' ' + number);
    }

    function mismatchAmount() {
        if (!counted || counted.value === '') return null;
        var value = parseFloat(counted.value);
        if (!isFinite(value)) return null;
        return Math.round((value - expected) * 100) / 100;
    }

    function syncDifference() {
        var diff = mismatchAmount();
        var mismatch = diff !== null && Math.abs(diff) >= 0.01;
        if (difference) difference.value = diff === null ? '' : formatMoney(diff);
        if (reason) reason.required = mismatch;
        if (star) star.classList.toggle('d-none', !mismatch);
        if (hint) hint.classList.toggle('d-none', !mismatch);
        return mismatch;
    }

    function printSlip() {
        syncDifference();
        var countedText = '—';
        if (counted && counted.value !== '') {
            countedText = formatMoney(parseFloat(counted.value) || 0);
        }
        var rows = [
            [root.getAttribute('data-lbl-branch'), root.getAttribute('data-branch')],
            [root.getAttribute('data-lbl-cashier'), root.getAttribute('data-cashier')],
            [root.getAttribute('data-lbl-shift'), root.getAttribute('data-shift')],
            [root.getAttribute('data-lbl-opening'), root.getAttribute('data-opening')],
            [root.getAttribute('data-lbl-sales'), root.getAttribute('data-sales')],
            [root.getAttribute('data-lbl-expected'), root.getAttribute('data-expected-label')],
            [root.getAttribute('data-lbl-counted'), countedText],
            [root.getAttribute('data-lbl-difference'), difference && difference.value ? difference.value : '—'],
            [root.getAttribute('data-lbl-reason'), reason && reason.value.trim() ? reason.value.trim() : '—']
        ];
        var body = rows.map(function (row) {
            return '<div class="row"><span>' + escapeHtml(row[0]) + '</span><span>' + escapeHtml(row[1]) + '</span></div>';
        }).join('');
        var win = window.open('', '_blank', 'width=420,height=700');
        if (!win) {
            alert(root.getAttribute('data-popup-blocked') || 'Allow pop-ups to print.');
            return;
        }
        var title = root.getAttribute('data-lbl-title') || 'Shift close';
        var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + escapeHtml(title) + '</title>';
        doc += '<style>body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#111}';
        doc += '#printarea{width:80mm;max-width:80mm;margin:0 auto;padding:3mm;font-size:12px;line-height:1.35}';
        doc += 'h1{font-size:14px;text-align:center;margin:0 0 3mm;text-transform:uppercase}';
        doc += '.row{display:flex;justify-content:space-between;gap:2mm;margin:1.2mm 0}';
        doc += '.row span:first-child{flex:1 1 auto}.row span:last-child{flex:0 0 auto;text-align:right;font-weight:600;white-space:pre-wrap;max-width:42mm}';
        doc += '@page{size:80mm auto;margin:3mm}';
        doc += '</style></head><body><div id="printarea"><h1>' + escapeHtml(title) + '</h1>' + body + '</div></body></html>';
        win.document.write(doc);
        win.document.close();
        win.focus();
        setTimeout(function () {
            win.print();
            setTimeout(function () { try { win.close(); } catch (e) {} }, 1500);
        }, 300);
    }

    if (counted) counted.addEventListener('input', syncDifference);
    if (reason) reason.addEventListener('input', syncDifference);
    var printBtn = root.querySelector('.shift-close-print');
    if (printBtn) printBtn.addEventListener('click', printSlip);
    if (form) {
        form.addEventListener('submit', function (event) {
            var mismatch = syncDifference();
            if (mismatch && reason && !reason.value.trim()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                reason.focus();
                if (typeof show_toastr === 'function') {
                    show_toastr(root.getAttribute('data-error-title') || 'Error', root.getAttribute('data-reason-required'), 'error');
                }
            }
        });
    }
    syncDifference();
};
