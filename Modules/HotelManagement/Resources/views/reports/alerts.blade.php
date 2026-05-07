@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Alerts') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Alerts') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="alert alert-warning mb-0">{{ __('Unassigned reservations (due today or past)') }}: <strong>{{ $unassignedToday }}</strong></div></div>
        <div class="col-md-4"><div class="alert alert-secondary mb-0">{{ __('Cancellations this month') }}: <strong>{{ $cancellations }}</strong></div></div>
    </div>
    <h6>{{ __('Late check-ins (past arrival date, not checked in)') }}</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm">
            <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Check-in date') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($lateCheckIns as $r)
                <tr>
                    <td>{{ trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')) }}</td>
                    <td>{{ $r->room->room_no ?? '—' }}</td>
                    <td>{{ $r->check_in_date }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.reservations.edit', $r->id) }}">{{ __('Open') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <h6>{{ __('Payment issues (open folios, underpaid)') }}</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>{{ __('Folio') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Grand') }}</th><th>{{ __('Paid') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($paymentIssues as $f)
                <tr>
                    <td>{{ $f->folio_no }}</td>
                    <td>{{ trim(($f->stay->reservation->guest->first_name ?? '').' '.($f->stay->reservation->guest->last_name ?? '')) }}</td>
                    <td>{{ Auth::user()->priceFormat($f->grand_total) }}</td>
                    <td>{{ Auth::user()->priceFormat($f->paid_total) }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.folios.show', $f->id) }}">{{ __('Folio') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection
