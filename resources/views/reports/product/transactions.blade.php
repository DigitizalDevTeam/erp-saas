@extends('layouts.admin')
@section('page-title')
    {{ __('Product transactions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Product transactions') }}</li>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.transactions') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Product') }}</label>
                    <select name="product_id" class="form-control">
                        <option value="">{{ __('All products') }}</option>
                        @foreach($products as $pid => $pname)
                            <option value="{{ $pid }}" {{ (string) request('product_id') === (string) $pid ? 'selected' : '' }}>{{ $pname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Movement type') }}</label>
                    <select name="movement_type" class="form-control">
                        <option value="">{{ __('All') }}</option>
                        <option value="purchase" {{ request('movement_type') === 'purchase' ? 'selected' : '' }}>{{ __('Purchase') }}</option>
                        <option value="invoice" {{ request('movement_type') === 'invoice' ? 'selected' : '' }}>{{ __('Invoice (sale)') }}</option>
                        <option value="pos" {{ request('movement_type') === 'pos' ? 'selected' : '' }}>{{ __('POS') }}</option>
                        <option value="bill" {{ request('movement_type') === 'bill' ? 'selected' : '' }}>{{ __('Bill') }}</option>
                        <option value="manually" {{ request('movement_type') === 'manually' ? 'selected' : '' }}>{{ __('Manual') }}</option>
                        <option value="warehouse_loss" {{ request('movement_type') === 'warehouse_loss' ? 'selected' : '' }}>{{ __('Loss / write-off') }}</option>
                        <option value="transfer" {{ request('movement_type') === 'transfer' ? 'selected' : '' }}>{{ __('Warehouse transfer') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('From') }}</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('To') }}</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.transactions') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.transactions.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.transactions', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="transactions-printable">
        <div class="card border-0 shadow-none bg-transparent mb-2 d-none-print-info">
            <div class="text-muted small mb-1">{{ __('Product transactions') }} — {{ now()->format('Y-m-d H:i') }}</div>
        </div>
        <div class="card">
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('IN / OUT') }}</th>
                            <th class="text-end">{{ __('Qty') }}</th>
                            <th>{{ __('Type') }}</th>
                            <th>{{ __('Reference') }}</th>
                            <th class="no-print">{{ __('Detail') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($movements as $m)
                            @php
                                $canDoc = $m->ref_id && in_array($m->ref_type, ['invoice', 'purchase', 'bill', 'pos', 'warehouse_transfer'], true);
                                $docType = $m->ref_type === 'warehouse_transfer' ? 'warehouse_transfer' : $m->ref_type;
                            @endphp
                            <tr>
                                <td>{{ $m->at ? \Carbon\Carbon::parse($m->at)->format('Y-m-d H:i') : '—' }}</td>
                                <td>{{ $m->product_name }}</td>
                                <td>
                                    @if($m->direction === 'in')
                                        <span class="badge bg-success">{{ __('IN') }}</span>
                                    @else
                                        <span class="badge bg-danger">{{ __('OUT') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($m->quantity, 2) }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $m->kind)) }}</td>
                                <td class="small">{{ \Illuminate\Support\Str::limit($m->reference, 80) }}</td>
                                <td class="no-print">
                                    @if($canDoc)
                                        <a href="{{ route('reports.product.document', ['docType' => $docType, 'id' => $m->ref_id]) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($movements->isEmpty())
                    <p class="text-muted text-center mb-0">{{ __('No movements for the selected filters.') }}</p>
                @endif
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
    #transactions-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.d-none-print-info { display: none; }
</style>
@endpush
