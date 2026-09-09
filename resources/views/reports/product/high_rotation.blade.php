@extends('layouts.admin')
@section('page-title')
    {{ __('High-value stock movements') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('High-value stock movements') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.high-rotation') }}" class="row g-3 align-items-end">
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
                        <option value="">{{ __('Invoice + POS') }}</option>
                        <option value="invoice" {{ request('source') === 'invoice' ? 'selected' : '' }}>{{ __('Invoice (sale)') }}</option>
                        <option value="pos" {{ request('source') === 'pos' ? 'selected' : '' }}>{{ __('POS') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Sort by') }}</label>
                    <select name="sort_by" class="form-control">
                        <option value="qty" {{ $sortBy === 'qty' ? 'selected' : '' }}>{{ __('Qty sold') }}</option>
                        <option value="value" {{ $sortBy === 'value' ? 'selected' : '' }}>{{ __('Sale value') }}</option>
                        <option value="rotation" {{ $sortBy === 'rotation' ? 'selected' : '' }}>{{ __('Rotation') }}</option>
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
                    <a href="{{ route('reports.product.high-rotation') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.high-rotation.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.high-rotation', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="high-rotation-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('High-value stock movements') }} — {{ $dateFrom }} → {{ $dateTo }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <p class="text-muted mb-3 no-print">
                    {{ __('Products with the highest sales rotation (quantity sold via invoice and POS).') }}
                    {{ __('Rotation') }} = {{ __('Qty sold') }} / {{ __('Current stock') }}.
                </p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Rank') }}</th>
                                <th>{{ __('ID') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Description') }}</th>
                                <th class="text-end">{{ __('Qty sold') }}</th>
                                <th class="text-end">{{ __('Movements') }}</th>
                                <th class="text-end">{{ __('Est. sale value') }}</th>
                                <th class="text-end">{{ __('Current stock') }}</th>
                                <th class="text-end">{{ __('Rotation') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $i => $r)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $r->product_id }}</td>
                                    <td>{{ $r->sku ?: '—' }}</td>
                                    <td>{{ $r->description ?: '—' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $r->qty_sold, 2) }}</td>
                                    <td class="text-end">{{ $r->movements }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($r->sale_value) }}</td>
                                    <td class="text-end">{{ number_format((float) $r->current_stock, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $r->rotation, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted">{{ __('No sales movements found for the selected period.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
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
    #high-rotation-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 10mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
