@extends('layouts.admin')
@section('page-title'){{ __('Sales by Waiter') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales by Waiter') }}</li>
@endsection
@section('content')
<div class="row"><div class="col-12"><div class="card"><div class="card-body">
    <form method="get" class="row g-3 mb-4">
        <div class="col-auto"><label class="form-label">{{ __('Start date') }}</label><input type="date" name="start_date" class="form-control" value="{{ $start }}"></div>
        <div class="col-auto"><label class="form-label">{{ __('End date') }}</label><input type="date" name="end_date" class="form-control" value="{{ $end }}"></div>
        <div class="col-auto d-flex align-items-end"><button type="submit" class="btn btn-primary">{{ __('Apply') }}</button></div>
    </form>
    <table class="table table-sm"><thead><tr><th>{{ __('Waiter') }}</th><th class="text-end">{{ __('Orders') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead>
    <tbody>
        @forelse($byWaiter as $row)<tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['count'] }}</td><td class="text-end">{{ Auth::user()->priceFormat($row['total']) }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted">{{ __('No sales in period.') }}</td></tr>@endforelse
    </tbody></table>
</div></div></div></div>
@endsection
