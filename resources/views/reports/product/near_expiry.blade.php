@extends('layouts.admin')
@section('page-title')
    {{ __('Products near expiry') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Products near expiry') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.near-expiry') }}" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">{{ __('Days ahead') }}</label>
                    <input type="number" name="days" min="1" max="365" value="{{ $days }}" class="form-control">
                </div>
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
                    <label class="form-label">{{ __('Search name / SKU / batch') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" name="use_product_alert_days" value="1" id="use_product_alert_days" {{ request('use_product_alert_days') ? 'checked' : '' }}>
                        <label class="form-check-label" for="use_product_alert_days">{{ __('Respect product expiry alert days') }}</label>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.near-expiry') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.near-expiry.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.near-expiry', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="near-expiry-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Products near expiry') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <p class="text-muted mb-3 no-print">
                    {{ __('Lots with remaining stock expiring within the selected number of days.') }}
                    {{ __('Default') }}: <strong>{{ $days }}</strong> {{ __('days') }}.
                </p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Batch / Lot') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th>{{ __('Expiry date') }}</th>
                                <th class="text-end">{{ __('Days left') }}</th>
                                <th class="text-end">{{ __('Qty remaining') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                @php
                                    $expiry = $r->expiry_date ? \Carbon\Carbon::parse($r->expiry_date) : null;
                                    $daysLeft = $expiry ? \Carbon\Carbon::today()->diffInDays($expiry, false) : null;
                                @endphp
                                <tr>
                                    <td>{{ $r->product_name }}</td>
                                    <td>{{ $r->sku ?: '—' }}</td>
                                    <td>{{ $r->batch_no ?: '—' }}</td>
                                    <td>{{ $r->warehouse_name ?: '—' }}</td>
                                    <td>{{ $expiry ? $expiry->format('Y-m-d') : '—' }}</td>
                                    <td class="text-end {{ $daysLeft !== null && $daysLeft <= 7 ? 'text-danger fw-semibold' : '' }}">
                                        {{ $daysLeft !== null ? $daysLeft : '—' }}
                                    </td>
                                    <td class="text-end">{{ number_format((float) $r->quantity_remaining, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">{{ __('No products near expiry for the selected filters.') }}</td>
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
    #near-expiry-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
