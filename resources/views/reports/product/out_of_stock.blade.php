@extends('layouts.admin')
@section('page-title')
    {{ __('Out-of-stock products') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Out-of-stock products') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.out-of-stock') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Category') }}</label>
                    <select name="category_id" class="form-control">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach($categories as $cid => $cname)
                            <option value="{{ $cid }}" {{ (string) request('category_id') === (string) $cid ? 'selected' : '' }}>{{ $cname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Search name / SKU') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.out-of-stock') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.out-of-stock.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.out-of-stock', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="out-of-stock-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Out-of-stock products') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <p class="text-muted mb-3 no-print">{{ __('Products and kits with stock equal to zero or below.') }}</p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th class="text-end">{{ __('Current Stock') }}</th>
                                <th class="text-end">{{ __('Sale Price') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $p)
                                <tr>
                                    <td>{{ $p->name }}</td>
                                    <td>{{ $p->sku ?: '—' }}</td>
                                    <td>{{ $p->category->name ?? '—' }}</td>
                                    <td>{{ ucfirst($p->type) }}</td>
                                    <td class="text-end text-danger">{{ $p->quantity }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($p->sale_price ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">{{ __('No out-of-stock products found.') }}</td>
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
    #out-of-stock-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
