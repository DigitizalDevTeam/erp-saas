@extends('layouts.admin')
@section('page-title')
    {{ __('Total stock value') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Total stock value') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.total-stock-value') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All warehouses') }}</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" {{ (string) request('warehouse_id') === (string) $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Search ID / SKU / Description') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.total-stock-value') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.total-stock-value.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.total-stock-value', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="total-stock-value-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Total stock value') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <p class="text-muted mb-3 no-print">
                    {{ __('Stock quantity with purchase and sale values, with and without VAT.') }}
                </p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('ID') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Description') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th class="text-end">{{ __('QTY') }}</th>
                                <th class="text-end">{{ __('Purchase Value (without VAT)') }}</th>
                                <th class="text-end">{{ __('Purchase Value (with VAT)') }}</th>
                                <th class="text-end">{{ __('Sale Value (without VAT)') }}</th>
                                <th class="text-end">{{ __('Sale Value (with VAT)') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                <tr>
                                    <td>{{ $r->product_id }}</td>
                                    <td>{{ $r->sku ?: '—' }}</td>
                                    <td>{{ $r->description ?: '—' }}</td>
                                    <td>{{ $r->warehouse_name ?: '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $r->qty, 2) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($r->purchase_ex_vat) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($r->purchase_inc_vat) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($r->sale_ex_vat) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($r->sale_inc_vat) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted">{{ __('No stock found for the selected filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="4" class="text-end">{{ __('Total') }}</td>
                                    <td class="text-end">{{ number_format((float) $totals['qty'], 2) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($totals['purchase_ex_vat']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($totals['purchase_inc_vat']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($totals['sale_ex_vat']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($totals['sale_inc_vat']) }}</td>
                                </tr>
                            </tfoot>
                        @endif
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
    #total-stock-value-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 10mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
