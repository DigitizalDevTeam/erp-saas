{{-- Cost Centre allocation modal (Phase 1). Included on the Bill and Expense
     edit screens. One modal serves every line: the JS fills it with the
     line's source amount and any existing posted split. --}}
@can('manage cost centre allocations')
<div class="modal fade" id="ccAllocationModal" tabindex="-1" role="dialog" aria-labelledby="ccAllocationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ccAllocationModalLabel">{{ __('Allocate Cost Centres') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Source amount') }}</label>
                        <input type="text" class="form-control" id="ccSourceAmount" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Basis') }}</label>
                        <select class="form-control" id="ccBasis">
                            <option value="percentage">{{ __('Percentage') }}</option>
                            <option value="fixed_amount">{{ __('Fixed amount') }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Template') }}</label>
                        <select class="form-control" id="ccTemplateSelect">
                            <option value="">{{ __('No template') }}</option>
                        </select>
                    </div>
                </div>

                <div id="ccAllocationErrors" class="alert alert-danger d-none"></div>
                <div id="ccAllocationWarnings" class="alert alert-warning d-none"></div>

                <table class="table mb-2" id="ccAllocationRows">
                    <thead>
                        <tr>
                            <th>{{ __('Cost centre') }}</th>
                            <th id="ccValueHeader">{{ __('Percentage') }} (%)</th>
                            <th class="text-end">{{ __('Allocated amount') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-primary" id="ccAddRow">{{ __('Add cost centre') }}</button>

                <div class="row mt-3">
                    <div class="col-md-4"><strong>{{ __('Total allocated') }}:</strong> <span id="ccTotalAllocated">0.00</span></div>
                    <div class="col-md-4"><strong>{{ __('Sum') }}:</strong> <span id="ccLiveSum">0%</span></div>
                    <div class="col-md-4"><strong>{{ __('Rounding difference') }}:</strong> <span id="ccRoundingDifference">0.00</span></div>
                </div>

                <div class="row g-2 mt-3">
                    <div class="col-md-8">
                        <input type="text" class="form-control" id="ccTemplateName" placeholder="{{ __('Save as template — template name') }}">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-secondary w-100" id="ccSaveTemplate">{{ __('Save as template') }}</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto d-none" id="ccReverseBtn">{{ __('Reverse allocation') }}</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-outline-primary" id="ccPreviewBtn">{{ __('Preview split') }}</button>
                <button type="button" class="btn btn-primary" id="ccSaveBtn">{{ __('Save allocation') }}</button>
            </div>
        </div>
    </div>
</div>

@push('script-page')
<script>
(function () {
    var sourceType = @json($ccSourceType ?? 'bill');
    var sourceId = @json($ccSourceId ?? 0);
    var centresCache = null;
    var templatesCache = null;
    var currentLine = null; // {lineType, lineId, amount}
    var currentAllocationId = null;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var input = document.querySelector('input[name="_token"]');
        return input ? input.value : '';
    }

    function headers() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        };
    }

    function showErrors(messages) {
        var box = document.getElementById('ccAllocationErrors');
        if (!messages || !messages.length) { box.classList.add('d-none'); box.innerHTML = ''; return; }
        box.classList.remove('d-none');
        box.innerHTML = messages.map(function (m) { return '<div>' + m + '</div>'; }).join('');
    }

    function showWarnings(messages) {
        var box = document.getElementById('ccAllocationWarnings');
        if (!messages || !messages.length) { box.classList.add('d-none'); box.innerHTML = ''; return; }
        box.classList.remove('d-none');
        box.innerHTML = messages.map(function (m) { return '<div>' + m + '</div>'; }).join('');
    }

    function loadCentres() {
        if (centresCache) return Promise.resolve(centresCache);
        return fetch(@json(route('cost-centre-allocations.centres')), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { centresCache = data.centres || []; return centresCache; });
    }

    function loadTemplates() {
        if (templatesCache) return Promise.resolve(templatesCache);
        return fetch(@json(route('cost-centre-allocation-templates.index')), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                templatesCache = data.templates || [];
                var sel = document.getElementById('ccTemplateSelect');
                templatesCache.forEach(function (t) {
                    var opt = document.createElement('option');
                    opt.value = t.id; opt.textContent = t.name + ' (' + t.basis + ')';
                    sel.appendChild(opt);
                });
                return templatesCache;
            });
    }

    function centreOptions(selectedId) {
        return (centresCache || []).map(function (c) {
            return '<option value="' + c.id + '"' + (String(c.id) === String(selectedId) ? ' selected' : '') + '>' + c.code + ' — ' + c.name + '</option>';
        }).join('');
    }

    function readRows() {
        var rows = [];
        document.querySelectorAll('#ccAllocationRows tbody tr').forEach(function (tr, i) {
            var centreId = tr.querySelector('.cc-centre').value;
            var value = tr.querySelector('.cc-value').value;
            if (!centreId) return;
            var row = { cost_centre_id: parseInt(centreId, 10), sort_order: i };
            if (document.getElementById('ccBasis').value === 'percentage') {
                row.percentage = value === '' ? null : value;
            } else {
                row.fixed_amount = value === '' ? null : value;
            }
            rows.push(row);
        });
        return rows;
    }

    function addRow(prefill) {
        prefill = prefill || {};
        var tbody = document.querySelector('#ccAllocationRows tbody');
        var tr = document.createElement('tr');
        var basis = document.getElementById('ccBasis').value;
        var value = basis === 'percentage' ? (prefill.percentage ?? '') : (prefill.fixed_amount ?? '');
        tr.innerHTML = '<td><select class="form-control cc-centre"><option value="">' + @json(__('Select cost centre')) + '</option>' + centreOptions(prefill.cost_centre_id) + '</select></td>'
            + '<td><input type="number" step="any" min="0" class="form-control cc-value" value="' + value + '"></td>'
            + '<td class="text-end cc-allocated">—</td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger cc-remove-row">×</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.cc-remove-row').addEventListener('click', function () { tr.remove(); updateLiveSum(); });
        tr.querySelector('.cc-value').addEventListener('input', updateLiveSum);
        tr.querySelector('.cc-centre').addEventListener('change', updateLiveSum);
    }

    function updateLiveSum() {
        var basis = document.getElementById('ccBasis').value;
        var rows = readRows();
        var sum = 0;
        rows.forEach(function (r) {
            sum += parseFloat(basis === 'percentage' ? (r.percentage || 0) : (r.fixed_amount || 0)) || 0;
        });
        document.getElementById('ccLiveSum').textContent = basis === 'percentage'
            ? sum.toFixed(6).replace(/\.?0+$/, '') + '%'
            : sum.toFixed(2);
    }

    function setRows(lines, basis) {
        document.querySelector('#ccAllocationRows tbody').innerHTML = '';
        document.getElementById('ccBasis').value = basis || 'percentage';
        document.getElementById('ccValueHeader').textContent = basis === 'fixed_amount' ? @json(__('Fixed amount')) : @json(__('Percentage')) + ' (%)';
        (lines || []).forEach(function (l) { addRow(l); });
        if (!(lines || []).length) addRow();
        updateLiveSum();
    }

    function preview() {
        var rows = readRows();
        return fetch(@json(route('cost-centre-allocations.validate')), {
            method: 'POST', headers: headers(),
            body: JSON.stringify({
                source_amount: currentLine.amount,
                basis: document.getElementById('ccBasis').value,
                lines: rows
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.ok) { showErrors(data.errors); return null; }
            showErrors([]);
            showWarnings(data.warnings);
            document.getElementById('ccTotalAllocated').textContent = data.total_allocated;
            document.getElementById('ccRoundingDifference').textContent = data.rounding_difference;
            var trs = document.querySelectorAll('#ccAllocationRows tbody tr');
            data.lines.forEach(function (line, i) {
                if (trs[i]) trs[i].querySelector('.cc-allocated').textContent = line.allocated_amount;
            });
            return data;
        });
    }

    function openModal(lineType, lineId, amount) {
        currentLine = { lineType: lineType, lineId: lineId, amount: amount };
        currentAllocationId = null;
        document.getElementById('ccSourceAmount').value = parseFloat(amount).toFixed(2);
        document.getElementById('ccReverseBtn').classList.add('d-none');
        showErrors([]); showWarnings([]);
        document.getElementById('ccTotalAllocated').textContent = '0.00';
        document.getElementById('ccRoundingDifference').textContent = '0.00';

        Promise.all([loadCentres(), loadTemplates()]).then(function () {
            return fetch(@json(route('cost-centre-allocations.by-line')) + '?source_type=' + encodeURIComponent(sourceType)
                + '&source_id=' + encodeURIComponent(sourceId)
                + '&source_line_type=' + encodeURIComponent(lineType)
                + '&source_line_id=' + encodeURIComponent(lineId), { headers: { 'Accept': 'application/json' } });
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.allocation) {
                currentAllocationId = data.allocation.id;
                setRows(data.allocation.lines, data.allocation.basis);
                document.getElementById('ccReverseBtn').classList.remove('d-none');
            } else {
                setRows([], 'percentage');
            }
        });

        var modal = new bootstrap.Modal(document.getElementById('ccAllocationModal'));
        modal.show();
    }

    document.getElementById('ccBasis').addEventListener('change', function () {
        var basis = this.value;
        document.getElementById('ccValueHeader').textContent = basis === 'fixed_amount' ? @json(__('Fixed amount')) : @json(__('Percentage')) + ' (%)';
        document.querySelectorAll('#ccAllocationRows tbody tr .cc-value').forEach(function (input) { input.value = ''; });
        document.querySelectorAll('#ccAllocationRows tbody tr .cc-allocated').forEach(function (td) { td.textContent = '—'; });
        updateLiveSum();
    });

    document.getElementById('ccAddRow').addEventListener('click', function () { addRow(); });

    document.getElementById('ccTemplateSelect').addEventListener('change', function () {
        var id = this.value;
        if (!id) return;
        var tpl = (templatesCache || []).find(function (t) { return String(t.id) === String(id); });
        if (tpl) setRows(tpl.lines, tpl.basis);
    });

    document.getElementById('ccPreviewBtn').addEventListener('click', function () { preview(); });

    document.getElementById('ccSaveBtn').addEventListener('click', function () {
        preview().then(function (data) {
            if (!data) return;
            fetch(@json(route('cost-centre-allocations.store')), {
                method: 'POST', headers: headers(),
                body: JSON.stringify({
                    source_type: sourceType, source_id: sourceId,
                    source_line_type: currentLine.lineType, source_line_id: currentLine.lineId,
                    basis: document.getElementById('ccBasis').value,
                    lines: readRows()
                })
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (!res.ok) { showErrors(res.errors); return; }
                bootstrap.Modal.getInstance(document.getElementById('ccAllocationModal')).hide();
                markAllocated(currentLine.lineType, currentLine.lineId);
            });
        });
    });

    document.getElementById('ccSaveTemplate').addEventListener('click', function () {
        var name = document.getElementById('ccTemplateName').value.trim();
        if (!name) { showErrors([@json(__('Enter a template name first.'))]); return; }
        fetch(@json(route('cost-centre-allocation-templates.store')), {
            method: 'POST', headers: headers(),
            body: JSON.stringify({ name: name, basis: document.getElementById('ccBasis').value, lines: readRows() })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.ok) { showErrors(res.errors); return; }
            templatesCache = null;
            document.getElementById('ccTemplateSelect').innerHTML = '<option value="">' + @json(__('No template')) + '</option>';
            loadTemplates();
            showErrors([]);
            document.getElementById('ccTemplateName').value = '';
        });
    });

    document.getElementById('ccReverseBtn').addEventListener('click', function () {
        if (!currentAllocationId) return;
        var reason = prompt(@json(__('Reason for reversing this allocation (required):')));
        if (!reason) return;
        fetch(@json(route('cost-centre-allocations.reverse', ['allocation' => 0])).replace('/0/reverse', '/' + currentAllocationId + '/reverse'), {
            method: 'POST', headers: headers(),
            body: JSON.stringify({ reason: reason })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.ok) { showErrors(res.errors); return; }
            bootstrap.Modal.getInstance(document.getElementById('ccAllocationModal')).hide();
            markAllocated(currentLine.lineType, currentLine.lineId, false);
        });
    });

    function markAllocated(lineType, lineId, allocated) {
        allocated = allocated === undefined ? true : allocated;
        document.querySelectorAll('.cc-allocate-btn').forEach(function (btn) {
            var item = btn.closest('[data-repeater-item]');
            if (!item) return;
            var idInput = lineType === 'bill_product' ? item.querySelector('input.id') : item.querySelector('input.account_id');
            if (idInput && String(idInput.value) === String(lineId)) {
                btn.classList.toggle('btn-success', allocated);
                btn.classList.toggle('btn-outline-primary', !allocated);
                btn.textContent = allocated ? @json(__('Allocated')) : @json(__('Allocate'));
            }
        });
    }

    // Allocate buttons (delegated — repeater rows are cloned by JS).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.cc-allocate-btn');
        if (!btn) return;
        e.preventDefault();
        var item = btn.closest('[data-repeater-item]');
        if (!item) return;
        var lineType = btn.getAttribute('data-cc-line-type');
        var idInput = lineType === 'bill_product' ? item.querySelector('input.id') : item.querySelector('input.account_id');
        var lineId = idInput ? parseInt(idInput.value, 10) : 0;
        if (!lineId) { alert(@json(__('Save the bill first — a line can only be allocated once it exists.'))); return; }
        var amount = 0;
        if (lineType === 'bill_product') {
            amount = parseFloat((item.querySelector('.amount') || {}).textContent || '0') || 0;
        } else {
            amount = parseFloat((item.querySelector('.accountAmount') || {}).value || '0') || 0;
        }
        if (!(amount > 0)) { alert(@json(__('Enter a line amount first.'))); return; }
        openModal(lineType, lineId, amount.toFixed(2));
    });

    // On load, mark lines that already have a posted allocation.
    document.addEventListener('DOMContentLoaded', function () {
        loadCentres();
        loadTemplates();
    });
})();
</script>
@endpush
@endcan
