@extends('layouts.admin')
@section('page-title')
    {{ __('Minimum Stock Alert Report') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Minimum Stock Alert') }}</li>
@endsection

@section('content')
    <div id="minimum-stock-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Minimum Stock Alert Report') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 no-print">
                    <div>
                        <p class="text-muted mb-0">
                            {{ __('Products with stock equal to or less than their Low stock alert at value.') }}
                            {{ __('Default threshold') }}: <strong>{{ $globalThreshold }}</strong>
                            {{ __('(for items without a per-product setting)') }}
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('report.minimum.stock.alert.export', request()->query()) }}" class="btn btn-primary">
                            <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                        </a>
                        <button type="button" class="btn btn-secondary" onclick="window.print();">
                            <i class="ti ti-printer"></i> {{ __('Print') }}
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped align-middle" id="minimum-stock-alert-table">
                        <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th class="text-end">{{ __('Current Stock') }}</th>
                                <th class="text-end">{{ __('Low stock alert at') }}</th>
                                <th class="text-end">{{ __('Shortage') }}</th>
                                <th class="text-end">{{ __('Sale Price') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $p)
                                @php
                                    $threshold = $p->low_stock_threshold !== null ? (float) $p->low_stock_threshold : (float) $globalThreshold;
                                    $shortage = $threshold - (float) $p->quantity;
                                @endphp
                                <tr>
                                    <td>{{ $p->name }}</td>
                                    <td>{{ $p->sku ?: '—' }}</td>
                                    <td>{{ ucfirst($p->type) }}</td>
                                    <td class="text-end">{{ $p->quantity }}</td>
                                    <td class="text-end">{{ $threshold }}</td>
                                    <td class="text-end text-danger">{{ $shortage > 0 ? $shortage : 0 }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($p->sale_price ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">{{ __('No products at or below the minimum stock alert.') }}</td>
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
    #minimum-stock-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
