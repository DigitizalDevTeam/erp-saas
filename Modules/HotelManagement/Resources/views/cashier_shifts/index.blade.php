@extends('layouts.admin')
@section('page-title'){{ __('Cashier shifts') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Cashier shifts') }}</li>
@endsection
@section('content')
<div class="row g-3">
    @if($canManage ?? false)
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Open shift') }}</h6>
            </div>
            <div class="card-body">
                @if($openShift)
                    <div class="alert alert-success border-0 small mb-3">
                        <i class="ti ti-check me-1"></i>
                        @if((int) $openShift->user_id === (int) Auth::id())
                            {{ __('You have an open shift since :time.', ['time' => optional($openShift->opened_at)->format('Y-m-d H:i')]) }}
                        @else
                            {{ __('Open shift #:id (:cashier) since :time.', [
                                'id' => $openShift->id,
                                'cashier' => $openShift->user->name ?? __('Cashier'),
                                'time' => optional($openShift->opened_at)->format('Y-m-d H:i'),
                            ]) }}
                        @endif
                    </div>
                    @if($openShiftSummary ?? null)
                        <div class="border rounded p-3 mb-3 small bg-light">
                            <div class="d-flex justify-content-between mb-1">
                                <span>{{ __('Opening float') }}</span>
                                <span>{{ Auth::user()->priceFormat($openShift->opening_float) }}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 text-danger">
                                <span>{{ __('Petty cash expenses') }}</span>
                                <span>− {{ Auth::user()->priceFormat($openShiftSummary['expenses_total'] ?? 0) }}</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                <span>{{ __('Remaining petty cash') }}</span>
                                <span>{{ Auth::user()->priceFormat($openShiftSummary['remaining_petty_cash'] ?? 0) }}</span>
                            </div>
                        </div>
                    @endif
                    <a href="{{ route('hotel.cashier-shifts.close-form', $openShift) }}" class="btn btn-primary w-100">
                        <i class="ti ti-lock me-1"></i>{{ __('Close my shift') }}
                    </a>
                    <a href="{{ route('hotel.cashier-shifts.show', $openShift) }}" class="btn btn-outline-secondary w-100 mt-2">
                        {{ __('View open shift') }}
                    </a>
                @else
                    {!! Form::open(['route' => 'hotel.cashier-shifts.open', 'method' => 'POST']) !!}
                    <div class="mb-3">
                        <label class="form-label">{{ __('Opening float') }}</label>
                        {!! Form::number('opening_float', old('opening_float', 0), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'required' => true]) !!}
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Operational shift') }}</label>
                        {!! Form::select('operational_shift', ['' => __('Select')] + $operationalShiftOptions, old('operational_shift'), ['class' => 'form-control']) !!}
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ti ti-player-play me-1"></i>{{ __('Open shift') }}
                    </button>
                    {!! Form::close() !!}
                @endif
            </div>
        </div>
    </div>
    @endif

    <div class="{{ ($canManage ?? false) ? 'col-lg-8' : 'col-12' }}">
        <div class="card shadow-sm border-0">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Shift history') }}</h6>
            </div>
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Cashier') }}</th>
                                <th>{{ __('Shift') }}</th>
                                <th>{{ __('Opened') }}</th>
                                <th>{{ __('Closed') }}</th>
                                <th class="text-end">{{ __('Cash collected') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($shifts as $shift)
                            <tr>
                                <td>{{ $shift->user->name ?? '—' }}</td>
                                <td>{{ \Modules\HotelManagement\Entities\HotelCashierShift::operationalShiftOptions()[$shift->operational_shift] ?? ($shift->operational_shift ?: '—') }}</td>
                                <td>{{ optional($shift->opened_at)->format('Y-m-d H:i') }}</td>
                                <td>{{ optional($shift->closed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="text-end">{{ Auth::user()->priceFormat($shift->cash_collected ?? 0) }}</td>
                                <td>
                                    <span class="badge bg-{{ $shift->status === 'open' ? 'warning' : 'success' }} {{ $shift->status === 'open' ? 'text-dark' : '' }}">
                                        {{ __(ucfirst($shift->status)) }}
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.cashier-shifts.show', $shift) }}">
                                        <i class="ti ti-eye"></i>
                                    </a>
                                    @if(($canManage ?? false) && $shift->status === 'open')
                                        <a class="btn btn-sm btn-primary" href="{{ route('hotel.cashier-shifts.close-form', $shift) }}">
                                            <i class="ti ti-lock"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center">{{ __('No cashier shifts yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
