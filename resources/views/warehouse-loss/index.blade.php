@extends('layouts.admin')

@section('page-title')
    {{ __('Warehouse Loss') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Warehouse Loss') }}</li>
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'warehouse-loss.store', 'method' => 'post', 'id' => 'warehouse-loss-form']) }}
                    <div class="row">
                        <div class="form-group col-md-4">
                            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}
                            {{ Form::select('warehouse_id', $warehouses, null, ['class' => 'form-control select', 'required' => true, 'placeholder' => __('Select Warehouse')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                            {{ Form::date('date', date('Y-m-d'), ['class' => 'form-control']) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('issuer_branch', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::text('issuer_branch_display', $issuerBranchName !== '' ? $issuerBranchName : '—', ['class' => 'form-control', 'readonly' => true, 'disabled' => true]) }}
                        </div>
                        <div class="form-group col-md-12">
                            {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}
                            {{ Form::text('reason', null, ['class' => 'form-control', 'placeholder' => __('Damaged, expired, missing, etc.')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('issuer_name', __('Issued By'), ['class' => 'form-label']) }}
                            {{ Form::text('issuer_name', \Auth::user()->name ?? '', ['class' => 'form-control', 'required' => true, 'placeholder' => __('Person who issued this write-off')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('authorized_by', __('Authorized By'), ['class' => 'form-label']) }}
                            {{ Form::text('authorized_by', null, ['class' => 'form-control', 'required' => true, 'placeholder' => __('Authorizing person name')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('witness_name', __('Witness Name'), ['class' => 'form-label']) }}
                            {{ Form::text('witness_name', null, ['class' => 'form-control', 'required' => true, 'placeholder' => __('Witness person name')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('delivery_note_required', __('Delivery Note Required'), ['class' => 'form-label d-block']) }}
                            <input type="hidden" name="delivery_note_required" value="0">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="delivery_note_required" id="delivery_note_required" value="1">
                                <label class="form-check-label" for="delivery_note_required">{{ __('Enable if deteriorated goods will be moved somewhere else') }}</label>
                            </div>
                        </div>
                        <div class="form-group col-md-8">
                            {{ Form::label('delivery_destination', __('Delivery Destination / Note'), ['class' => 'form-label']) }}
                            {{ Form::text('delivery_destination', null, ['class' => 'form-control', 'placeholder' => __('Where goods are being sent / delivery note reference')]) }}
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-2 mb-2">
                        <h6 class="mb-0">{{ __('Products') }}</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="wl-add-line">{{ __('Add line') }}</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm" id="wl-items-table">
                            <thead>
                                <tr>
                                    <th style="width:40%;">{{ __('Product') }}</th>
                                    <th style="width:15%;">{{ __('Lost Qty') }}</th>
                                    <th style="width:18%;">{{ __('Unit Cost') }}</th>
                                    <th style="width:17%;">{{ __('Cost of Goods') }}</th>
                                    <th style="width:10%;"></th>
                                </tr>
                            </thead>
                            <tbody id="wl-items-body"></tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">{{ __('Total Cost of Goods') }}</th>
                                    <th><span id="wl-total-cost">0.00</span></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-3 ms-auto">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add Loss') }}</button>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0">{{ __('Write-off History') }}</h5>
                    <button type="button" id="wl-print-selected" class="btn btn-sm btn-primary" disabled>
                        <i class="ti ti-printer"></i> {{ __('Print Selected') }}
                    </button>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable" id="wl-history-table">
                            <thead>
                                <tr>
                                    <th style="width:36px;">
                                        <input type="checkbox" id="wl-select-all" class="form-check-input" title="{{ __('Select all') }}">
                                    </th>
                                    <th>{{ __('Write-off #') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Lines') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Cost of Goods') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th>{{ __('Issued / Authorized / Witness') }}</th>
                                    <th>{{ __('Delivery Option') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($documents as $r)
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input wl-print-cb" value="{{ $r->print_id }}">
                                        </td>
                                        <td>{{ $r->write_off_no }}</td>
                                        <td>{{ \Auth::user()->dateFormat($r->date) }}</td>
                                        <td>{{ $r->warehouse->name ?? '-' }}</td>
                                        <td>{{ $r->product_label }}</td>
                                        <td>{{ $r->line_count }}</td>
                                        <td>{{ $r->quantity }}</td>
                                        <td>{{ \Auth::user()->priceFormat($r->cost_of_goods ?? 0) }}</td>
                                        <td>{{ $r->reason ?? '-' }}</td>
                                        <td>
                                            {{ $r->issuer_name ?? '-' }} /
                                            {{ $r->authorized_by ?? '-' }} /
                                            {{ $r->witness_name ?? '-' }}
                                        </td>
                                        <td>
                                            @if((int) ($r->delivery_note_required ?? 0) === 1)
                                                <span class="badge bg-warning">{{ __('Required') }}</span>
                                                <div class="small text-muted mt-1">{{ $r->delivery_destination ?? '-' }}</div>
                                            @else
                                                <span class="badge bg-secondary">{{ __('No') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('warehouse-loss.print', $r->print_id) }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="ti ti-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
(function () {
    var products = @json($products);
    var productCosts = @json($productCosts);
    var lineIndex = 0;
    var bulkPrintUrl = @json(route('warehouse-loss.print-bulk'));

    function productOptionsHtml(selected) {
        var html = '<option value="">{{ __("Select Product") }}</option>';
        Object.keys(products).forEach(function (id) {
            html += '<option value="' + id + '"' + (String(selected) === String(id) ? ' selected' : '') + '>' + products[id] + '</option>';
        });
        return html;
    }

    function round2(n) {
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function syncLineCost(tr) {
        var qty = parseFloat(tr.querySelector('.wl-qty').value) || 0;
        var unit = parseFloat(tr.querySelector('.wl-unit-cost').value) || 0;
        var lineCost = round2(qty * unit);
        tr.querySelector('.wl-line-cost').value = lineCost.toFixed(2);
        syncTotalCost();
    }

    function syncTotalCost() {
        var total = 0;
        document.querySelectorAll('#wl-items-body .wl-line-cost').forEach(function (el) {
            total += parseFloat(el.value) || 0;
        });
        var out = document.getElementById('wl-total-cost');
        if (out) {
            out.textContent = round2(total).toFixed(2);
        }
    }

    function fillUnitCostFromProduct(tr) {
        var pid = tr.querySelector('.wl-product').value;
        var unitInput = tr.querySelector('.wl-unit-cost');
        if (pid && productCosts[pid] !== undefined) {
            unitInput.value = parseFloat(productCosts[pid] || 0).toFixed(4);
        }
        syncLineCost(tr);
    }

    function addLine(productId, qty, unitCost) {
        var idx = lineIndex++;
        var tr = document.createElement('tr');
        tr.className = 'wl-line';
        var cost = unitCost;
        if (cost === undefined || cost === null || cost === '') {
            cost = (productId && productCosts[productId] !== undefined) ? productCosts[productId] : '';
        }
        tr.innerHTML =
            '<td><select name="items[' + idx + '][product_id]" class="form-control select wl-product" required>' + productOptionsHtml(productId || '') + '</select></td>' +
            '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control wl-qty" step="0.0001" min="0.0001" required value="' + (qty || '') + '"></td>' +
            '<td><input type="number" name="items[' + idx + '][unit_cost]" class="form-control wl-unit-cost" step="0.0001" min="0" required value="' + (cost !== '' ? parseFloat(cost).toFixed(4) : '') + '"></td>' +
            '<td><input type="number" class="form-control wl-line-cost" step="0.01" min="0" readonly tabindex="-1" value="0.00"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger wl-remove-line">{{ __("Remove") }}</button></td>';
        document.getElementById('wl-items-body').appendChild(tr);
        if (window.$ && $.fn.select2) {
            $(tr).find('select').select2({ width: '100%' });
        }
        syncLineCost(tr);
    }

    function selectedPrintIds() {
        return Array.prototype.map.call(document.querySelectorAll('.wl-print-cb:checked'), function (el) {
            return el.value;
        }).filter(Boolean);
    }

    function syncPrintSelectedBtn() {
        var btn = document.getElementById('wl-print-selected');
        if (btn) {
            btn.disabled = selectedPrintIds().length === 0;
        }
    }

    document.getElementById('wl-add-line').addEventListener('click', function () {
        addLine('', '');
    });
    document.getElementById('wl-items-body').addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('wl-remove-line')) {
            var rows = document.querySelectorAll('#wl-items-body tr');
            if (rows.length <= 1) {
                return;
            }
            e.target.closest('tr').remove();
            syncTotalCost();
        }
    });
    document.getElementById('wl-items-body').addEventListener('change', function (e) {
        var tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.classList.contains('wl-product')) {
            fillUnitCostFromProduct(tr);
        } else if (e.target.classList.contains('wl-qty') || e.target.classList.contains('wl-unit-cost')) {
            syncLineCost(tr);
        }
    });
    document.getElementById('wl-items-body').addEventListener('input', function (e) {
        var tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.classList.contains('wl-qty') || e.target.classList.contains('wl-unit-cost')) {
            syncLineCost(tr);
        }
    });
    if (window.$) {
        $(document).on('select2:select', '#wl-items-body .wl-product', function () {
            fillUnitCostFromProduct(this.closest('tr'));
        });
    }

    addLine('', '');

    var selectAll = document.getElementById('wl-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.wl-print-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            syncPrintSelectedBtn();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('wl-print-cb')) {
            syncPrintSelectedBtn();
        }
    });

    var printSelectedBtn = document.getElementById('wl-print-selected');
    if (printSelectedBtn) {
        printSelectedBtn.addEventListener('click', function () {
            var ids = selectedPrintIds();
            if (!ids.length) {
                return;
            }
            var params = new URLSearchParams();
            ids.forEach(function (id) {
                params.append('ids[]', id);
            });
            window.open(bulkPrintUrl + '?' + params.toString(), '_blank');
        });
    }
})();
</script>
@endpush
