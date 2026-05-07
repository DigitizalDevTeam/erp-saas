@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Financial report') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Financial') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
    <div class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.reports.financial.export', request()->query()) }}">{{ __('Export Excel') }}</a>
    </div>
    <p><strong>{{ __('Discounts (folio lines, period)') }}:</strong> {{ Auth::user()->priceFormat($discountTotal) }}</p>
    @if($methodTotals->isNotEmpty())
        <h6>{{ __('Payments by method') }}</h6>
        <ul>
            @foreach($methodTotals as $m => $t)
                <li><strong>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $m))) }}:</strong> {{ Auth::user()->priceFormat($t) }}</li>
            @endforeach
        </ul>
    @endif
    <div class="table-responsive">
        <table class="table table-striped table-sm">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Revenue (folios)') }}</th><th>{{ __('Paid on folio') }}</th></tr></thead>
            <tbody>
            @foreach($folioByDay as $row)
                <tr>
                    <td>{{ $row->d }}</td>
                    <td>{{ Auth::user()->priceFormat($row->revenue) }}</td>
                    <td>{{ Auth::user()->priceFormat($row->paid) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection
