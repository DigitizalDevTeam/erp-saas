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
                        $cardTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', [\App\Models\PosPayment::METHOD_CARD, \App\Models\PosPayment::METHOD_MPESA, \App\Models\PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'])->sum('amount');
                        $creditTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
                        $cashDropsTotal = (float) ($pos_shift->cashDrops->sum('amount') ?? 0);
                        $expectedCash = $pos_shift->opening_balance + $cashTotal - $cashDropsTotal;
                    @endphp
                    <p><strong>{{ __('Sales (this shift)') }}:</strong> {{ $shiftSalesCount }} {{ __('transactions') }}, {{ \Auth::user()->priceFormat($shiftSalesTotal) }}</p>
                    <p><strong>{{ __('Cash (in)') }}:</strong> {{ \Auth::user()->priceFormat($cashTotal) }}</p>
                    <p><strong>{{ __('Card / M-Pesa / Bank transfer (in)') }}:</strong> {{ \Auth::user()->priceFormat($cardTotal) }}</p>
                    <p><strong>{{ __('Credit (in)') }}:</strong> {{ \Auth::user()->priceFormat($creditTotal) }}</p>
                    @if($cashDropsTotal > 0)
                    <p><strong>{{ __('Cash drops (out)') }}:</strong> {{ \Auth::user()->priceFormat($cashDropsTotal) }}</p>
                    @endif
                    <p><strong>{{ __('Expected cash (opening + cash in − cash drops)') }}:</strong> {{ \Auth::user()->priceFormat($expectedCash) }}</p>
                    <hr>
                    {{ Form::open(['route' => ['pos-shift.close', $pos_shift], 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="form-group">
                        {{ Form::label('closing_balance', __('Closing Balance'), ['class' => 'form-label']) }} <x-required></x-required>
                        {{ Form::number('closing_balance', null, ['class' => 'form-control', 'required' => 'required', 'min' => 0, 'step' => '0.01', 'placeholder' => __('Enter closing balance')]) }}
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
