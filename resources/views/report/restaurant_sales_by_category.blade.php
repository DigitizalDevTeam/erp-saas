@extends('layouts.admin')
@section('page-title')
    {{ __('Sales by Categories') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales by Categories') }}</li>
@endsection

@push('css-page')
<style>
    .category-report-table th, .category-report-table td { vertical-align: middle; }
    .category-report-table tfoot td { font-weight: 700; }
    .report-note { font-size: 0.85rem; color: #6c757d; }
</style>
@endpush

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.restaurant.sales-by-category', 'method' => 'get']) }}
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            {{ Form::label('scope', __('Report Scope'), ['class' => 'form-label']) }}
                            {{ Form::select('scope', ['shift' => __('Shift-wise'), 'eod' => __('End of Day')], $filter['scope'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('with_stock', __('Stock in report'), ['class' => 'form-label']) }}
                            {{ Form::select('with_stock', [1 => __('With stock'), 0 => __('Without stock')], $filter['with_stock'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::select('branch_id', $filterOptions['branches'], $filter['branch_id'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}
                            {{ Form::select('warehouse_id', $filterOptions['warehouses'], $filter['warehouse_id'], ['class' => 'form-control']) }}
                        </div>

                        <div class="col-md-4">
                            {{ Form::label('shift_id', __('Shift'), ['class' => 'form-label']) }}
                            {{ Form::select('shift_id', $filterOptions['shifts'], $filter['shift_id'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-2">
                            {{ Form::label('date', __('Date (EOD)'), ['class' => 'form-label']) }}
                            {{ Form::date('date', $filter['date'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-2">
                            {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                            {{ Form::date('start_date', $filter['start_date'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-2">
                            {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                            {{ Form::date('end_date', $filter['end_date'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button class="btn btn-primary w-100" type="submit">{{ __('Apply') }}</button>
                            <a href="{{ route('report.restaurant.sales-by-category') }}" class="btn btn-light">{{ __('Reset') }}</a>
                        </div>
                    </div>
                    {{ Form::close() }}
                    <div class="mt-2">
                        <a href="{{ route('report.restaurant.sales-by-category.export', request()->query()) }}" class="btn btn-sm btn-success">
                            <i class="ti ti-file-export"></i> {{ __('Export Excel') }}
                        </a>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-2 col-6"><div class="card"><div class="card-body py-2"><div class="text-muted small">{{ __('Categories') }}</div><div class="h6 mb-0">{{ $summary['categories_count'] }}</div></div></div></div>
                <div class="col-md-2 col-6"><div class="card"><div class="card-body py-2"><div class="text-muted small">{{ __('Qty Sold') }}</div><div class="h6 mb-0">{{ number_format($summary['total_qty'], 2) }}</div></div></div></div>
                <div class="col-md-3 col-6"><div class="card"><div class="card-body py-2"><div class="text-muted small">{{ __('Sales Total') }}</div><div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['total_sales']) }}</div></div></div></div>
                <div class="col-md-2 col-6"><div class="card"><div class="card-body py-2"><div class="text-muted small">{{ __('Stock Qty') }}</div><div class="h6 mb-0">{{ number_format($summary['total_stock'], 2) }}</div></div></div></div>
                <div class="col-md-3 col-12"><div class="card"><div class="card-body py-2"><div class="text-muted small">{{ __('Min/Max Alerts') }}</div><div class="h6 mb-0 text-danger">{{ $summary['total_min_alerts'] }} / {{ $summary['total_max_alerts'] }}</div></div></div></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Sales by Categories') }}</h5>
                    <small class="report-note">{{ __('Designed for shift closing and night audit checks.') }}</small>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered category-report-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Category') }}</th>
                                    <th class="text-end">{{ __('QTY') }}</th>
                                    <th class="text-end">{{ __('AMOUNT') }}</th>
                                    <th class="text-end">%</th>
                                    @if((int) $filter['with_stock'] === 1)
                                        <th class="text-end">{{ __('Stock') }}</th>
                                        <th class="text-center">{{ __('Min Alert') }}</th>
                                        <th class="text-center">{{ __('Max Alert') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    <tr>
                                        <td>{{ $row['category_name'] }}</td>
                                        <td class="text-end">{{ number_format($row['qty_sold'], 2) }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($row['amount_sold']) }}</td>
                                        <td class="text-end">{{ number_format($row['sales_pct'], 2) }}%</td>
                                        @if((int) $filter['with_stock'] === 1)
                                            <td class="text-end">{{ number_format((float) ($row['stock_qty'] ?? 0), 2) }}</td>
                                            <td class="text-center">{{ (int) ($row['min_alerts'] ?? 0) }}</td>
                                            <td class="text-center">{{ (int) ($row['max_alerts'] ?? 0) }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ (int) $filter['with_stock'] === 1 ? 7 : 4 }}" class="text-center text-muted">{{ __('No data in this period') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($rows->isNotEmpty())
                                <tfoot>
                                    <tr>
                                        <td>{{ __('TOTAL') }}</td>
                                        <td class="text-end">{{ number_format($summary['total_qty'], 2) }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($summary['total_sales']) }}</td>
                                        <td class="text-end">100%</td>
                                        @if((int) $filter['with_stock'] === 1)
                                            <td class="text-end">{{ number_format($summary['total_stock'], 2) }}</td>
                                            <td class="text-center">{{ $summary['total_min_alerts'] }}</td>
                                            <td class="text-center">{{ $summary['total_max_alerts'] }}</td>
                                        @endif
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>

            @if((int) $filter['with_stock'] === 1)
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('Min / Max Stock Alerts') }}</h6></div>
                    <div class="card-body table-border-style">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Category') }}</th>
                                        <th>{{ __('Product') }}</th>
                                        <th>{{ __('SKU') }}</th>
                                        <th class="text-end">{{ __('Stock') }}</th>
                                        <th class="text-end">{{ __('Threshold') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($stockAlerts as $alert)
                                        <tr>
                                            <td>
                                                @if($alert['alert_type'] === 'min')
                                                    <span class="badge bg-danger">{{ __('MIN') }}</span>
                                                @else
                                                    <span class="badge bg-warning text-dark">{{ __('MAX') }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $alert['category_name'] }}</td>
                                            <td>{{ $alert['product_name'] }}</td>
                                            <td>{{ $alert['sku'] ?: '—' }}</td>
                                            <td class="text-end">{{ number_format($alert['stock_qty'], 2) }}</td>
                                            <td class="text-end">{{ number_format($alert['threshold'], 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">{{ __('No min/max stock alerts for current filter.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
