@extends('layouts.admin')
@section('page-title')
    {{ $isReceipt ? __('Tag Receipt') : __('Payment Dimensions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-dimensions.index', ['type' => $isReceipt ? 'receipt' : 'bill_payment']) }}">{{ __('Treasury Dimensions') }}</a></li>
    <li class="breadcrumb-item">{{ $isReceipt ? __('Tag Receipt') : __('Payment Dimensions') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3"><strong>{{ $isReceipt ? __('Receipt') : __('Bill payment') }}:</strong> #{{ $payment->id }}</div>
                        <div class="col-md-3"><strong>{{ $isReceipt ? __('Invoice') : __('Bill') }}:</strong> #{{ $isReceipt ? $payment->invoice_id : $payment->bill_id }}</div>
                        <div class="col-md-3"><strong>{{ __('Date') }}:</strong> {{ $payment->date }}</div>
                        <div class="col-md-3"><strong>{{ __('Amount') }}:</strong> {{ number_format((float) $paymentAmount, 2) }}</div>
                    </div>

                    @if ($isReceipt)
                        <p class="text-muted">{{ __('Tagging a receipt is analysis only: it never changes the invoice, the customer balance or the bank balance.') }}</p>
                    @else
                        <p class="text-muted">{{ __('This payment settles a bill. By default it inherits the bill’s cost centre split — the expense was recognised once, at the bill. Change the split below to override it; the override only changes the cash view, never the expense.') }}</p>
                    @endif

                    @if (!empty($warnings))
                        <div class="alert alert-warning">
                            @foreach ($warnings as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if (!$isReceipt && $preview && !empty($preview['rows']))
                        <div class="alert alert-info">
                            <strong>{{ __('Inherited from the bill') }}:</strong>
                            @foreach ($preview['rows'] as $row)
                                <div>
                                    @php $centre = $centres->firstWhere('id', (int) $row['cost_centre_id']); @endphp
                                    {{ $centre ? $centre->code : '#' . $row['cost_centre_id'] }}:
                                    {{ number_format((float) $row['allocated_amount'], 2) }}
                                    ({{ rtrim(rtrim((string) $row['percentage'], '0'), '.') }}%)
                                </div>
                            @endforeach
                            <div><strong>{{ __('Inherited total') }}:</strong> {{ number_format((float) $preview['inherited_total'], 2) }} {{ __('of') }} {{ number_format((float) $preview['payment_amount'], 2) }}</div>
                        </div>
                    @endif
                    @if (!$isReceipt && $preview && empty($preview['rows']))
                        <div class="alert alert-warning">{{ __('The bill has no posted cost centre allocations, so there is nothing to inherit. Enter a split below, or leave it untagged.') }}</div>
                    @endif

                    @php
                        // Prefill: current dimensions first, else the inherited preview (bill payments), else one empty row.
                        // A manual fixed-amount split stores no percentage, so it is prefilled as fixed amounts
                        // (the allocated amounts) with the fixed basis selected.
                        $prefill = [];
                        $prefillBasis = 'percentage';
                        if ($dimensions->isNotEmpty()) {
                            $allFixed = $dimensions->every(fn ($d) => $d->percentage === null);
                            $prefillBasis = $allFixed ? 'fixed_amount' : 'percentage';
                            foreach ($dimensions as $d) {
                                $prefill[] = [
                                    'cost_centre_id' => (int) $d->cost_centre_id,
                                    'percentage' => $allFixed ? null : ($d->percentage !== null ? (string) $d->percentage : null),
                                    'fixed_amount' => $allFixed ? (string) $d->allocated_amount : null,
                                ];
                            }
                        } elseif (!$isReceipt && $preview && !empty($preview['rows'])) {
                            foreach ($preview['rows'] as $row) {
                                $prefill[] = ['cost_centre_id' => (int) $row['cost_centre_id'], 'percentage' => (string) $row['percentage'], 'fixed_amount' => null];
                            }
                        }
                        if (empty($prefill)) {
                            $prefill[] = ['cost_centre_id' => 0, 'percentage' => '', 'fixed_amount' => null];
                        }
                    @endphp

                    <form method="POST" action="{{ $isReceipt ? route('treasury-dimensions.receipt.update', $payment->id) : route('treasury-dimensions.bill-payment.update', $payment->id) }}" id="tdForm">
                        @csrf
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Basis') }}</label>
                                <select class="form-control" name="basis" id="tdBasis">
                                    <option value="percentage" {{ $prefillBasis === 'percentage' ? 'selected' : '' }}>{{ __('Percentage') }}</option>
                                    <option value="fixed_amount" {{ $prefillBasis === 'fixed_amount' ? 'selected' : '' }}>{{ __('Fixed amount') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Project (optional)') }}</label>
                                <select class="form-control" name="project_id">
                                    <option value="">{{ __('No project') }}</option>
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->id }}">{{ $project->project_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Payment amount') }}</label>
                                <input type="text" class="form-control" value="{{ number_format((float) $paymentAmount, 2) }}" readonly>
                            </div>
                        </div>

                        <div id="tdErrors" class="alert alert-danger d-none"></div>

                        <table class="table mb-2" id="tdRows">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th id="tdValueHeader">{{ $prefillBasis === 'fixed_amount' ? __('Fixed amount') : __('Percentage') . ' (%)' }}</th>
                                    <th class="text-end">{{ __('Allocated amount') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($prefill as $i => $row)
                                    <tr>
                                        <td>
                                            <select class="form-control td-centre" name="lines[{{ $i }}][cost_centre_id]">
                                                <option value="">{{ __('Select cost centre') }}</option>
                                                @foreach ($centres as $centre)
                                                    <option value="{{ $centre->id }}" {{ (int) $row['cost_centre_id'] === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="number" step="any" min="0" class="form-control td-value" name="lines[{{ $i }}][{{ $prefillBasis === 'fixed_amount' ? 'fixed_amount' : 'percentage' }}]" value="{{ $prefillBasis === 'fixed_amount' ? ($row['fixed_amount'] ?? '') : ($row['percentage'] ?? '') }}"></td>
                                        <td class="text-end td-allocated">—</td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger td-remove-row">×</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="tdAddRow">{{ __('Add cost centre') }}</button>

                        <div class="row mt-3">
                            <div class="col-md-4"><strong>{{ __('Total allocated') }}:</strong> <span id="tdTotalAllocated">0.00</span></div>
                            <div class="col-md-4"><strong>{{ __('Sum') }}:</strong> <span id="tdLiveSum">0%</span></div>
                            <div class="col-md-4"><strong>{{ __('Rounding difference') }}:</strong> <span id="tdRoundingDifference">0.00</span></div>
                        </div>

                        <div class="mt-4">
                            <button type="button" class="btn btn-outline-primary" id="tdPreviewBtn">{{ __('Preview split') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save dimensions') }}</button>
                            @if (!$isReceipt && $dimensions->isNotEmpty() && $dimensions->contains(fn ($d) => $d->source === 'manual'))
                                <button type="submit" name="reset_to_inherited" value="1" class="btn btn-outline-secondary">{{ __('Reset to inherited') }}</button>
                            @endif
                            <a href="{{ route('treasury-dimensions.index', ['type' => $isReceipt ? 'receipt' : 'bill_payment']) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
(function () {
    var paymentType = @json($isReceipt ? 'receipt' : 'bill_payment');
    var paymentId = @json((int) $payment->id);
    var centres = @json($centres->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all());

    function csrfToken() {
        var input = document.querySelector('#tdForm input[name="_token"]');
        return input ? input.value : '';
    }

    function showErrors(messages) {
        var box = document.getElementById('tdErrors');
        if (!messages || !messages.length) { box.classList.add('d-none'); box.innerHTML = ''; return; }
        box.classList.remove('d-none');
        box.innerHTML = messages.map(function (m) { return '<div>' + m + '</div>'; }).join('');
    }

    function centreOptions(selectedId) {
        return centres.map(function (c) {
            return '<option value="' + c.id + '"' + (String(c.id) === String(selectedId) ? ' selected' : '') + '>' + c.code + ' — ' + c.name + '</option>';
        }).join('');
    }

    function readRows() {
        var rows = [];
        document.querySelectorAll('#tdRows tbody tr').forEach(function (tr, i) {
            var centreId = tr.querySelector('.td-centre').value;
            var value = tr.querySelector('.td-value').value;
            if (!centreId) return;
            var row = { cost_centre_id: parseInt(centreId, 10), sort_order: i };
            if (document.getElementById('tdBasis').value === 'percentage') {
                row.percentage = value === '' ? null : value;
            } else {
                row.fixed_amount = value === '' ? null : value;
            }
            rows.push(row);
        });
        return rows;
    }

    function renumberRows() {
        document.querySelectorAll('#tdRows tbody tr').forEach(function (tr, i) {
            tr.querySelector('.td-centre').setAttribute('name', 'lines[' + i + '][cost_centre_id]');
            tr.querySelector('.td-value').setAttribute('name', 'lines[' + i + '][' + (document.getElementById('tdBasis').value === 'percentage' ? 'percentage' : 'fixed_amount') + ']');
        });
    }

    function addRow(prefill) {
        prefill = prefill || {};
        var tbody = document.querySelector('#tdRows tbody');
        var tr = document.createElement('tr');
        var basis = document.getElementById('tdBasis').value;
        var value = basis === 'percentage' ? (prefill.percentage ?? '') : (prefill.fixed_amount ?? '');
        tr.innerHTML = '<td><select class="form-control td-centre"><option value="">' + @json(__('Select cost centre')) + '</option>' + centreOptions(prefill.cost_centre_id) + '</select></td>'
            + '<td><input type="number" step="any" min="0" class="form-control td-value" value="' + value + '"></td>'
            + '<td class="text-end td-allocated">—</td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger td-remove-row">×</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.td-remove-row').addEventListener('click', function () { tr.remove(); renumberRows(); updateLiveSum(); });
        tr.querySelector('.td-value').addEventListener('input', updateLiveSum);
        tr.querySelector('.td-centre').addEventListener('change', updateLiveSum);
        renumberRows();
    }

    function updateLiveSum() {
        var basis = document.getElementById('tdBasis').value;
        var rows = readRows();
        var sum = 0;
        rows.forEach(function (r) {
            sum += parseFloat(basis === 'percentage' ? (r.percentage || 0) : (r.fixed_amount || 0)) || 0;
        });
        document.getElementById('tdLiveSum').textContent = basis === 'percentage'
            ? sum.toFixed(6).replace(/\.?0+$/, '') + '%'
            : sum.toFixed(2);
    }

    document.getElementById('tdBasis').addEventListener('change', function () {
        var basis = this.value;
        document.getElementById('tdValueHeader').textContent = basis === 'fixed_amount' ? @json(__('Fixed amount')) : @json(__('Percentage')) + ' (%)';
        document.querySelectorAll('#tdRows tbody tr .td-value').forEach(function (input) { input.value = ''; });
        document.querySelectorAll('#tdRows tbody tr .td-allocated').forEach(function (td) { td.textContent = '—'; });
        renumberRows();
        updateLiveSum();
    });

    document.getElementById('tdAddRow').addEventListener('click', function () { addRow(); });

    document.querySelectorAll('#tdRows tbody tr .td-remove-row').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('tr').remove(); renumberRows(); updateLiveSum(); });
    });
    document.querySelectorAll('#tdRows tbody tr .td-value').forEach(function (input) {
        input.addEventListener('input', updateLiveSum);
    });
    document.querySelectorAll('#tdRows tbody tr .td-centre').forEach(function (sel) {
        sel.addEventListener('change', updateLiveSum);
    });

    document.getElementById('tdPreviewBtn').addEventListener('click', function () {
        fetch(@json(route('treasury-dimensions.validate')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                payment_type: paymentType,
                payment_id: paymentId,
                basis: document.getElementById('tdBasis').value,
                lines: readRows()
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.ok) { showErrors(data.errors); return; }
            showErrors([]);
            document.getElementById('tdTotalAllocated').textContent = data.total_allocated;
            document.getElementById('tdRoundingDifference').textContent = data.rounding_difference;
            var trs = document.querySelectorAll('#tdRows tbody tr');
            data.lines.forEach(function (line, i) {
                if (trs[i]) trs[i].querySelector('.td-allocated').textContent = line.allocated_amount;
            });
        });
    });

    updateLiveSum();
})();
</script>
@endpush
