@extends('layouts.admin')
@section('page-title')
    {{ __('Row & column check') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Row & column check') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <p class="text-muted mb-3">{{ __('Stock in warehouse bins that have a row and/or shelf set (from purchases or manual stock-in), plus products with location only on the product card when no bin line exists yet.') }}</p>
            <form method="get" action="{{ route('reports.product.location-check') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All warehouses') }}</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" {{ (string) request('warehouse_id') === (string) $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Row contains') }}</label>
                    <input type="text" name="row_filter" value="{{ request('row_filter') }}" class="form-control" placeholder="{{ __('e.g. A1') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Shelf contains') }}</label>
                    <input type="text" name="shelf_filter" value="{{ request('shelf_filter') }}" class="form-control" placeholder="{{ __('e.g. 3') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.location-check') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.location-check.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.location-check', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="location-check-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Row & column check') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>

        <h6 class="text-uppercase text-muted mb-2">{{ __('Warehouse bins') }}</h6>
        @forelse($byLocation as $key => $group)
            @php
                $hdr = $group->first();
                $locParts = array_pad(explode('|', $key, 3), 3, '—');
                $rowLabel = $locParts[1];
                $shelfLabel = $locParts[2];
            @endphp
            <div class="card mb-3">
                <div class="card-header">
                    <strong>{{ __('Warehouse') }}:</strong> {{ $hdr->warehouse_name }}
                    &nbsp;|&nbsp;
                    <strong>{{ __('Row') }}:</strong> {{ $rowLabel }}
                    &nbsp;|&nbsp;
                    <strong>{{ __('Shelf') }}:</strong> {{ $shelfLabel }}
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th class="text-end">{{ __('Qty in bin') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($group as $r)
                                <tr>
                                    <td>{{ $r->product_name }}</td>
                                    <td>{{ $r->sku ?? '—' }}</td>
                                    <td class="text-end">{{ number_format((float) ($r->quantity ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="card mb-4"><div class="card-body text-muted">{{ __('No warehouse stock with row or shelf. Add row/shelf on a purchase line or when adding product stock.') }}</div></div>
        @endforelse

        @if($byLocationFallback->count() > 0)
            <h6 class="text-uppercase text-muted mb-2 mt-4">{{ __('Product card location only (global qty)') }}</h6>
            @foreach($byLocationFallback as $key => $group)
                @php
                    $fbParts = array_pad(explode('|', $key, 3), 3, '—');
                    $rowLabel = $fbParts[1];
                    $shelfLabel = $fbParts[2];
                @endphp
                <div class="card mb-3">
                    <div class="card-header">
                        <strong>{{ __('Row') }}:</strong> {{ $rowLabel }}
                        &nbsp;|&nbsp;
                        <strong>{{ __('Shelf') }}:</strong> {{ $shelfLabel }}
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                <tr>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('SKU') }}</th>
                                    <th class="text-end">{{ __('System qty') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($group as $p)
                                    <tr>
                                        <td>{{ $p->name }}</td>
                                        <td>{{ $p->sku ?? '—' }}</td>
                                        <td class="text-end">{{ number_format((float) ($p->quantity ?? 0), 2) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection

@push('css-page')
<style>
@media print {
    .no-print, .card .no-print, .sidebar, header, nav, .breadcrumb, .dash-hasmenu, .dash-submenu { display: none !important; }
    .d-none-print-info { display: block !important; }
    body { background: #fff !important; }
    .content-wrapper { padding: 0 !important; }
    #location-check-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
