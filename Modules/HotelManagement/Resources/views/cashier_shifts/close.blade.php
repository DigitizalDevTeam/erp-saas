@extends('layouts.admin')
@section('page-title'){{ __('Close cashier shift') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.cashier-shifts.index') }}">{{ __('Cashier shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Close') }}</li>
@endsection
@section('content')
@php $fmt = fn ($n) => Auth::user()->priceFormat($n); @endphp
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Collected during shift') }}</h6></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="d-flex justify-content-between py-1"><span>{{ __('Cash') }}</span><strong>{{ $fmt($totals['cash'] ?? 0) }}</strong></li>
                    <li class="d-flex justify-content-between py-1"><span>{{ __('Card') }}</span><strong>{{ $fmt($totals['card'] ?? 0) }}</strong></li>
                    <li class="d-flex justify-content-between py-1"><span>{{ __('Other') }}</span><strong>{{ $fmt($totals['other'] ?? 0) }}</strong></li>
                    <li class="d-flex justify-content-between py-2 border-top fw-bold"><span>{{ __('Total collected') }}</span><span>{{ $fmt($totals['total'] ?? 0) }}</span></li>
                </ul>
                <hr>
                <ul class="list-unstyled mb-0 small">
                    <li class="d-flex justify-content-between"><span>{{ __('Opening float (petty cash)') }}</span><span>{{ $fmt($shift->opening_float) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Cash collected') }}</span><span>{{ $fmt($totals['cash'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between text-danger"><span>{{ __('Petty cash expenses') }}</span><span>− {{ $fmt($expensesTotal ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Expected cash') }}</span><strong>{{ $fmt($expectedCash) }}</strong></li>
                    <li class="text-muted mt-2">{{ __('Opened') }}: {{ optional($shift->opened_at)->format('Y-m-d H:i') }}</li>
                    <li class="text-muted">{{ __('Expected = opening float + cash collected − expenses.') }}</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header"><h6 class="mb-0">{{ __('Close shift') }}</h6></div>
            <div class="card-body">
                @if($canManage ?? false)
                {!! Form::open(['route' => ['hotel.cashier-shifts.close', $shift->id], 'method' => 'POST']) !!}
                <div class="mb-3">
                    <label class="form-label">{{ __('Closing cash (counted)') }}</label>
                    {!! Form::number('closing_cash', old('closing_cash', $expectedCash), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'required' => true]) !!}
                    <small class="text-muted">{{ __('Difference = closing cash − expected cash.') }}</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Cash handover') }}</label>
                    {!! Form::number('cash_handover', old('cash_handover'), ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Handed to') }}</label>
                    {!! Form::text('handed_to', old('handed_to'), ['class' => 'form-control', 'placeholder' => __('Name or role')]) !!}
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Notes') }}</label>
                    {!! Form::textarea('notes', old('notes'), ['class' => 'form-control', 'rows' => 3]) !!}
                </div>
                <button type="submit" class="btn btn-primary" onclick="return confirm('{{ __('Close this cashier shift?') }}')">
                    <i class="ti ti-lock me-1"></i>{{ __('Close shift') }}
                </button>
                <a href="{{ route('hotel.cashier-shifts.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                {!! Form::close() !!}
                @else
                <p class="text-muted mb-0">{{ __('You do not have permission to close shifts.') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
