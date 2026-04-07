@extends('layouts.admin')
@section('page-title')
    {{ __('Close Shift') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Close Shift') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                    <p><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                    <p><strong>{{ __('Opening Balance') }}:</strong> {{ \Auth::user()->priceFormat($pos_shift->opening_balance) }}</p>
                    @php
                        $shiftSales = \App\Models\Pos::where('shift_id', $pos_shift->id)->get();
                        $shiftSalesCount = $shiftSales->count();
                        $posIds = $shiftSales->pluck('id');
                        $shiftSalesTotal = \App\Models\PosPayment::whereIn('pos_id', $posIds)->sum('amount');
                        $cashTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
                            $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
                        })->sum('amount');
                        $balanceCash = (float) \App\Models\InvoicePayment::where('pos_shift_id', $pos_shift->id)->where('payment_method', 0)->sum('amount');
                        $cashTotal += $balanceCash;
                        $cardTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', [\App\Models\PosPayment::METHOD_CARD, \App\Models\PosPayment::METHOD_MPESA, \App\Models\PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'])->sum('amount');
                        $creditTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
                        $cashDropsTotal = (float) \App\Models\PosCashDrop::where('shift_id', $pos_shift->id)->sum('amount');
                        $cashInsTotal = (float) \App\Models\PosCashIn::where('shift_id', $pos_shift->id)->sum('amount');
                        $cashOutExpensesTotal = (float) \App\Models\Expense::where('pos_shift_id', $pos_shift->id)->where('created_by', \Auth::user()->creatorId())->where('status', \App\Models\Expense::STATUS_APPROVED)->sum('amount');
                        $expectedCash = (float) ($pos_shift->opening_balance ?? 0) + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
                    @endphp
                    <p><strong>{{ __('Sales (this shift)') }}:</strong> {{ $shiftSalesCount }} {{ __('transactions') }}, {{ \Auth::user()->priceFormat($shiftSalesTotal) }}</p>
                    <p><strong>{{ __('Cash (in)') }}</strong>@if($balanceCash > 0)<span class="text-muted small"> ({{ __('incl. balance payments') }}: {{ \Auth::user()->priceFormat($balanceCash) }})</span>@endif: {{ \Auth::user()->priceFormat($cashTotal) }}</p>
                    @if($cashInsTotal > 0)
                        <p><strong>{{ __('Cash in (top-ups)') }}:</strong> {{ \Auth::user()->priceFormat($cashInsTotal) }}</p>
                    @endif
                    <p><strong>{{ __('Card / M-Pesa / Bank transfer (in)') }}:</strong> {{ \Auth::user()->priceFormat($cardTotal) }}</p>
                    <p><strong>{{ __('Credit (in)') }}:</strong> {{ \Auth::user()->priceFormat($creditTotal) }}</p>
                    <p><strong>{{ __('Cash drops (spent / out)') }}:</strong> {{ \Auth::user()->priceFormat($cashDropsTotal) }}</p>
                    @if($cashOutExpensesTotal > 0)
                        <p><strong>{{ __('Cash out (expenses)') }}:</strong> {{ \Auth::user()->priceFormat($cashOutExpensesTotal) }}</p>
                    @endif
                    <p><strong>{{ __('Expected cash (opening + cash in − cash drops)') }}:</strong> {{ \Auth::user()->priceFormat($expectedCash) }}</p>
                    @if($expectedCash < 0)
                        <p class="small text-muted mb-0">{{ __('Expected cash can be negative when cash drops exceed cash in. Enter the physical cash counted in the drawer (0 or more).') }}</p>
                    @endif
                    <hr>
                    {{ Form::open(['route' => ['pos-shift.close', $pos_shift], 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="form-group">
                        {{ Form::label('closing_balance', __('Closing Balance'), ['class' => 'form-label']) }} <x-required></x-required>
                        {{ Form::number('closing_balance', old('closing_balance', $expectedCash), ['class' => 'form-control' . ($errors->has('closing_balance') ? ' is-invalid' : ''), 'required' => 'required', 'step' => '0.01', 'placeholder' => __('Physical cash in drawer')]) }}
                        @error('closing_balance')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mt-2">
                        {{ Form::label('tips', __('Tips'), ['class' => 'form-label']) }}
                        {{ Form::number('tips', $pos_shift->tips ?? 0, ['class' => 'form-control', 'min' => 0, 'step' => '0.01']) }}
                    </div>
                    <div class="form-group mt-2">
                        {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
                        {{ Form::textarea('notes', $pos_shift->notes ?? '', ['class' => 'form-control', 'rows' => 3]) }}
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-danger">{{ __('Close Shift') }}</button>
                        <a href="{{ route('pos-shift.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
