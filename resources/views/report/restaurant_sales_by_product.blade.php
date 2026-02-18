@extends('layouts.admin')
@section('page-title'){{ __('Sales by Product') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales by Product') }}</li>
@endsection
@section('content')
<div class="row"><div class="col-12"><div class="card"><div class="card-body">
    <form method="get" class="row g-3 mb-4">
        <div class="col-auto"><label class="form-label">{{ __('Start date') }}</label><input type="date" name="start_date" class="form-control" value="{{ $start }}"></div>
        <div class="col-auto"><label class="form-label">{{ __('End date') }}</label><input type="date" name="end_date" class="form-control" value="{{ $end }}"></div>
        <div class="col-auto d-flex align-items-end"><button type="submit" class="btn btn-primary">{{ __('Apply') }}</button></div>
    </form>
    <table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Revenue') }}</th></tr></thead>
    <tbody>
        @forelse($byProduct as $row)<tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['quantity'] }}</td><td class="text-end">{{ Auth::user()->priceFormat($row['revenue']) }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted">{{ __('No sales in period.') }}</td></tr>@endforelse
    </tbody></table>
</div></div></div></div>
@endsection
