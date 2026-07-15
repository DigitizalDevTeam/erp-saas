@extends('layouts.admin')
@section('page-title'){{ __('Cashier shift') }} #{{ $shift->id }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.cashier-shifts.index') }}">{{ __('Cashier shifts') }}</a></li>
    <li class="breadcrumb-item">#{{ $shift->id }}</li>
@endsection
@section('content')
@php $fmt = fn ($n) => Auth::user()->priceFormat($n); @endphp
<div class="card shadow-sm border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">{{ __('Shift summary') }}</h6>
        <span class="badge bg-{{ $shift->status === 'open' ? 'warning' : 'success' }} {{ $shift->status === 'open' ? 'text-dark' : '' }}">
            {{ __(ucfirst($shift->status)) }}
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="ps-0">{{ __('Cashier') }}</th><td>{{ $shift->user->name ?? '—' }}</td></tr>
                    <tr><th class="ps-0">{{ __('Operational shift') }}</th><td>{{ \Modules\HotelManagement\Entities\HotelCashierShift::operationalShiftOptions()[$shift->operational_shift] ?? ($shift->operational_shift ?: '—') }}</td></tr>
                    <tr><th class="ps-0">{{ __('Opened at') }}</th><td>{{ optional($shift->opened_at)->format('Y-m-d H:i') }}</td></tr>
                    <tr><th class="ps-0">{{ __('Closed at') }}</th><td>{{ optional($shift->closed_at)->format('Y-m-d H:i') ?: '—' }}</td></tr>
                    <tr><th class="ps-0">{{ __('Closed by') }}</th><td>{{ $shift->closedByUser->name ?? '—' }}</td></tr>
                    <tr><th class="ps-0">{{ __('Handed to') }}</th><td>{{ $shift->handed_to ?: '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-bordered mb-0">
                    <tr><td>{{ __('Opening float (petty cash)') }}</td><td class="text-end">{{ $fmt($shift->opening_float) }}</td></tr>
                    <tr><td>{{ __('Cash collected') }}</td><td class="text-end">{{ $fmt($shift->cash_collected) }}</td></tr>
                    <tr><td>{{ __('Petty cash expenses') }}</td><td class="text-end text-danger">− {{ $fmt($shift->expenses_total ?? 0) }}</td></tr>
                    <tr><td>{{ __('Card collected') }}</td><td class="text-end">{{ $fmt($shift->card_collected) }}</td></tr>
                    <tr><td>{{ __('Other collected') }}</td><td class="text-end">{{ $fmt($shift->other_collected) }}</td></tr>
                    <tr><td>{{ __('Expected cash') }}</td><td class="text-end">{{ $fmt($shift->expected_cash) }}</td></tr>
                    <tr><td>{{ __('Closing cash') }}</td><td class="text-end">{{ $fmt($shift->closing_cash) }}</td></tr>
                    <tr class="fw-bold"><td>{{ __('Difference') }}</td><td class="text-end">{{ $fmt($shift->difference) }}</td></tr>
                    <tr><td>{{ __('Cash handover') }}</td><td class="text-end">{{ $fmt($shift->cash_handover) }}</td></tr>
                </table>
            </div>
        </div>
        @if($shift->notes)
            <div class="mt-3">
                <label class="form-label small text-muted mb-1">{{ __('Notes') }}</label>
                <p class="mb-0">{{ $shift->notes }}</p>
            </div>
        @endif
        <div class="mt-3">
            <a href="{{ route('hotel.cashier-shifts.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
            @if($shift->status === 'open')
                <a href="{{ route('hotel.cashier-shifts.close-form', $shift) }}" class="btn btn-primary">{{ __('Close shift') }}</a>
            @endif
        </div>
    </div>
</div>
@endsection
