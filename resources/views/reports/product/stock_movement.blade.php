@extends('layouts.admin')
@section('page-title')
    {{ __('Stock Movement report') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Stock Movement report') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.stock-movement') }}" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">{{ __('From') }}</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('To') }}</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Source') }}</label>
                    <select name="source" class="form-control">
                        <option value="">{{ __('All') }}</option>
                        <option value="transfer" {{ request('source') === 'transfer' ? 'selected' : '' }}>{{ __('Warehouse transfer') }}</option>
                        <option value="loss" {{ request('source') === 'loss' ? 'selected' : '' }}>{{ __('Loss / write-off') }}</option>
                        <option value="delete" {{ request('source') === 'delete' ? 'selected' : '' }}>{{ __('Delete') }}</option>
                        <option value="pos" {{ request('source') === 'pos' ? 'selected' : '' }}>{{ __('POS sale') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Sort by') }}</label>
                    <select name="sort_by" class="form-control">
                        <option value="date" {{ $sortBy === 'date' ? 'selected' : '' }}>{{ __('Date') }}</option>
                        <option value="qty" {{ $sortBy === 'qty' ? 'selected' : '' }}>{{ __('Movement') }}</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">{{ __('Top') }}</label>
                    <input type="number" name="limit" min="10" max="500" value="{{ $limit }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Search ID / SKU / Description') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.stock-movement') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.stock-movement.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.stock-movement', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-danger">
                        <i class="ti ti-printer text-white"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="stock-movement-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Stock Movement report') }} — {{ $dateFrom }} → {{ $dateTo }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <p class="text-muted mb-3 no-print">
                    {{ __('Warehouse transfers, losses, deleted transfers, and POS sales with from/to warehouses and reasons.') }}
                </p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('#') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('ID') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Description') }}</th>
                                <th class="text-end">{{ __('Movement') }}</th>
                                <th>{{ __('From') }}</th>
                                <th>{{ __('To') }}</th>
                                <th class="text-end">{{ __('Current stock') }}</th>
                                <th>{{ __('Reason') }}</th>
                                <th class="no-print">{{ __('View Details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $i => $r)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td class="text-nowrap">{{ $r->sort_date ? date('Y-m-d H:i', strtotime($r->sort_date)) : '—' }}</td>
                                    <td>{{ $r->product_id }}</td>
                                    <td>{{ $r->sku ?: '—' }}</td>
                                    <td>{{ $r->description ?: '—' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $r->movement, 2) }}</td>
                                    <td>{{ $r->from_warehouse }}</td>
                                    <td>{{ $r->to_warehouse }}</td>
                                    <td class="text-end">
                                        @if($r->to_warehouse_stock !== null)
                                            {{ number_format((float) $r->to_warehouse_stock, 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="small">{{ \Illuminate\Support\Str::limit($r->reason, 60) }}</td>
                                    <td class="no-print">
                                        @if(in_array($r->detail_kind, ['loss', 'delete'], true) && $r->detail_id)
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary js-stock-movement-view"
                                                    data-kind="{{ $r->detail_kind }}"
                                                    data-id="{{ $r->detail_id }}">
                                                {{ __('View') }}
                                            </button>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted">{{ __('No stock movements found for the selected period.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="stockMovementDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stockMovementDetailTitle">{{ __('View Details') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <div id="stockMovementDetailLoading" class="text-muted">{{ __('Loading…') }}</div>
                    <div id="stockMovementDetailBody" class="d-none">
                        <dl class="row mb-0" id="stockMovementDetailList"></dl>
                    </div>
                    <div id="stockMovementDetailError" class="alert alert-danger d-none mb-0"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('css-page')
<style>
@media print {
    .no-print, .card .no-print, .sidebar, header, nav, .breadcrumb, .dash-hasmenu, .dash-submenu { display: none !important; }
    .d-none-print-info { display: block !important; }
    body { background: #fff !important; }
    .content-wrapper { padding: 0 !important; }
    #stock-movement-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 10mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush

@push('script-page')
<script>
(function () {
    var detailBase = @json(url('reports/product-system/stock-movement'));
    var labels = {
        product: @json(__('Product')),
        sku: @json(__('SKU')),
        quantity: @json(__('Quantity')),
        date: @json(__('Date')),
        datetime: @json(__('Date and time')),
        reason: @json(__('Reason')),
        from: @json(__('From')),
        to: @json(__('To')),
        performed_by: @json(__('Performed by')),
        authorized_by: @json(__('Authorized by')),
        witness_name: @json(__('Witness')),
        write_off_no: @json(__('Write-off no.')),
        unit_cost: @json(__('Unit cost')),
        cost_of_goods: @json(__('Cost of goods')),
        delivery_destination: @json(__('Delivery destination')),
        type: @json(__('Type')),
        reference_id: @json(__('Reference'))
    };

    function detailUrl(kind, id) {
        return detailBase + '/' + encodeURIComponent(kind) + '/' + id + '/detail';
    }

    function addRow(dl, label, value) {
        if (value === undefined || value === null || value === '') {
            return;
        }
        var dt = document.createElement('dt');
        dt.className = 'col-sm-4 text-muted';
        dt.textContent = label;
        var dd = document.createElement('dd');
        dd.className = 'col-sm-8';
        dd.textContent = value;
        dl.appendChild(dt);
        dl.appendChild(dd);
    }

    document.querySelectorAll('.js-stock-movement-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kind = btn.getAttribute('data-kind');
            var id = btn.getAttribute('data-id');
            var modalEl = document.getElementById('stockMovementDetailModal');
            var loading = document.getElementById('stockMovementDetailLoading');
            var body = document.getElementById('stockMovementDetailBody');
            var err = document.getElementById('stockMovementDetailError');
            var list = document.getElementById('stockMovementDetailList');
            var title = document.getElementById('stockMovementDetailTitle');

            loading.classList.remove('d-none');
            body.classList.add('d-none');
            err.classList.add('d-none');
            err.textContent = '';
            list.innerHTML = '';

            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            fetch(detailUrl(kind, id), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error(@json(__('Could not load details.')));
                    }
                    return res.json();
                })
                .then(function (data) {
                    loading.classList.add('d-none');
                    body.classList.remove('d-none');
                    title.textContent = data.title || @json(__('View Details'));
                    addRow(list, labels.write_off_no, data.write_off_no);
                    addRow(list, labels.product, data.product);
                    addRow(list, labels.sku, data.sku);
                    addRow(list, labels.quantity, data.quantity);
                    addRow(list, labels.date, data.date);
                    addRow(list, labels.datetime, data.datetime);
                    addRow(list, labels.reason, data.reason);
                    addRow(list, labels.from, data.from_warehouse);
                    addRow(list, labels.to, data.to_warehouse);
                    addRow(list, labels.performed_by, data.performed_by);
                    addRow(list, labels.authorized_by, data.authorized_by);
                    addRow(list, labels.witness_name, data.witness_name);
                    addRow(list, labels.unit_cost, data.unit_cost);
                    addRow(list, labels.cost_of_goods, data.cost_of_goods);
                    addRow(list, labels.delivery_destination, data.delivery_destination);
                    addRow(list, labels.type, data.type);
                    addRow(list, labels.reference_id, data.reference_id);
                })
                .catch(function (e) {
                    loading.classList.add('d-none');
                    err.classList.remove('d-none');
                    err.textContent = e.message || @json(__('Could not load details.'));
                });
        });
    });
})();
</script>
@endpush
