@extends('layouts.admin')
@section('page-title'){{ __('Discounts Applied') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Discounts') }}</li>
@endsection
@section('content')
<div class="row"><div class="col-12"><div class="card"><div class="card-body">
    <p class="text-muted">Total discounts: <strong>{{ Auth::user()->priceFormat($totalDiscount) }}</strong></p>
    <form method="get" class="row g-3 mb-4">
        <div class="col-auto"><label class="form-label">Start date</label><input type="date" name="start_date" class="form-control" value="{{ $start }}"></div>
        <div class="col-auto"><label class="form-label">End date</label><input type="date" name="end_date" class="form-control" value="{{ $end }}"></div>
        <div class="col-auto d-flex align-items-end"><button type="submit" class="btn btn-primary">Apply</button></div>
    </form>
    <table class="table table-sm"><thead><tr><th>POS #</th><th>Date</th><th class="text-end">Line discount</th><th class="text-end">Payment discount</th></tr></thead>
    <tbody>
        @forelse($rows as $pos)@php $lineDisc = $pos->items->sum('discount'); $payDisc = $pos->posPayments->sum('discount_amount'); @endphp<tr><td>{{ $pos->pos_id ?? $pos->id }}</td><td>{{ $pos->pos_date }}</td><td class="text-end">{{ Auth::user()->priceFormat($lineDisc) }}</td><td class="text-end">{{ Auth::user()->priceFormat($payDisc) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">No discounts.</td></tr>@endforelse
    </tbody></table>
</div></div></div></div>
@endsection
