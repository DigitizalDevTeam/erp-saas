@extends('layouts.admin')
@section('page-title')
    {{ __('Expired products') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Expired products') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.expired-products') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All warehouses') }}</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" {{ (string) request('warehouse_id') === (string) $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Remaining stock') }}</label>
                    <select name="remaining" class="form-control">
                        <option value="with_stock" {{ request('remaining', 'with_stock') === 'with_stock' ? 'selected' : '' }}>{{ __('With remaining stock') }}</option>
                        <option value="depleted" {{ request('remaining') === 'depleted' ? 'selected' : '' }}>{{ __('Depleted') }}</option>
                        <option value="all" {{ request('remaining') === 'all' ? 'selected' : '' }}>{{ __('All') }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Search name / SKU / batch') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.expired-products') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.expired-products.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.expired-products', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <form method="post" action="{{ route('reports.product.expired-products.convert-to-loss') }}" id="expired-to-loss-form" class="no-print"
          onsubmit="return confirm('{{ __('Convert selected expired lots to warehouse loss? Stock will be deducted and cost posted for accounting.') }}');">
        @csrf
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-end justify-content-between">
                <div class="text-muted small mb-0">
                    {{ __('Select expired lots with remaining stock, then convert them to Warehouse Loss for accounting.') }}
                </div>
                <button type="submit" class="btn btn-danger" id="btn-convert-loss" disabled>
                    <i class="ti ti-trash"></i> {{ __('Convert selected to loss') }}
                </button>
            </div>
        </div>

        <div id="expired-products-printable">
            <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
                <div class="text-muted small mb-1">{{ __('Expired products') }} — {{ now()->format('Y-m-d H:i') }}</div>
            </div>
            <div class="card">
                <div class="card-body table-border-style">
                    <p class="text-muted mb-3 no-print">{{ __('Lots whose expiry date has passed.') }}</p>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th class="no-print" style="width:36px;">
                                        <input type="checkbox" class="form-check-input" id="expired-select-all" title="{{ __('Select all') }}">
                                    </th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('SKU') }}</th>
                                    <th>{{ __('Batch / Lot') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Expiry date') }}</th>
                                    <th class="text-end">{{ __('Days overdue') }}</th>
                                    <th class="text-end">{{ __('Qty remaining') }}</th>
                                    <th class="text-end no-print">{{ __('Unit Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $r)
                                    @php
                                        $expiry = $r->expiry_date ? \Carbon\Carbon::parse($r->expiry_date) : null;
                                        $daysOverdue = $expiry ? $expiry->diffInDays(\Carbon\Carbon::today()) : null;
                                        $canConvert = (float) $r->quantity_remaining > 0 && !empty($r->warehouse_id);
                                    @endphp
                                    <tr>
                                        <td class="no-print">
                                            @if($canConvert)
                                                <input type="checkbox" class="form-check-input expired-lot-cb" name="lot_ids[]" value="{{ $r->id }}">
                                            @endif
                                        </td>
                                        <td>{{ $r->product_name }}</td>
                                        <td>{{ $r->sku ?: '—' }}</td>
                                        <td>{{ $r->batch_no ?: '—' }}</td>
                                        <td>{{ $r->warehouse_name ?: '—' }}</td>
                                        <td class="text-danger">{{ $expiry ? $expiry->format('Y-m-d') : '—' }}</td>
                                        <td class="text-end text-danger fw-semibold">{{ $daysOverdue !== null ? $daysOverdue : '—' }}</td>
                                        <td class="text-end">{{ number_format((float) $r->quantity_remaining, 2) }}</td>
                                        <td class="text-end no-print">{{ Auth::user()->priceFormat($r->purchase_price ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">{{ __('No expired products found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('css-page')
<style>
@media print {
    .no-print, .card .no-print, .sidebar, header, nav, .breadcrumb, .dash-hasmenu, .dash-submenu { display: none !important; }
    .d-none-print-info { display: block !important; }
    body { background: #fff !important; }
    .content-wrapper { padding: 0 !important; }
    #expired-products-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush

@push('script-page')
<script>
(function () {
    var selectAll = document.getElementById('expired-select-all');
    var btn = document.getElementById('btn-convert-loss');

    function sync() {
        var checked = document.querySelectorAll('.expired-lot-cb:checked').length;
        if (btn) btn.disabled = checked === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.expired-lot-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            sync();
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('expired-lot-cb')) {
            sync();
        }
    });
    sync();
})();
</script>
@endpush
