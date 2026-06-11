@extends('layouts.admin')
@section('page-title'){{ __('Room bill') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item">{{ __('Room bill') }} <span class="text-muted small">({{ __('Folio') }} {{ $folio->folio_no }})</span></li>
@endsection
@section('content')
@php
    $ps = $folio->paymentStatusLabel();
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-light border small mb-3">{{ __('Frontdesk workflow hint') }}</div>
        <div class="card shadow-sm"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">{{ $folio->folio_no }}</h5>
                    <p class="mb-1">{{ __('Guest') }}: {{ trim(($folio->stay->reservation->guest->first_name ?? '').' '.($folio->stay->reservation->guest->last_name ?? '')) }}</p>
                    <p class="mb-0">{{ __('Folio status') }}: {{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $folio->status))) }}</p>
                </div>
                <div class="text-end">
                    <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }} fs-6">{{ __('Payment') }}: {{ __(ucfirst($ps)) }}</span>
                    <p class="mb-0 mt-2"><strong>{{ __('Grand total') }}:</strong> {{ Auth::user()->priceFormat($folio->grand_total) }}</p>
                    <p class="mb-0"><strong>{{ __('Paid') }}:</strong> {{ Auth::user()->priceFormat($folio->paid_total) }}</p>
                    <a href="{{ route('hotel.folios.final-bill', $folio) }}" class="btn btn-sm btn-outline-primary mt-2">{{ __('Open final bill PDF') }}</a>
                    @if($taxInvoiceUrl ?? null)
                        <a href="{{ $taxInvoiceUrl }}" class="btn btn-sm btn-success mt-2" target="_blank">{{ __('Tax invoice PDF') }}</a>
                    @endif
                </div>
            </div>
            <hr>
            <h6>{{ __('Line items') }}</h6>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>{{ __('Description') }}</th><th>{{ __('Qty') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Total') }}</th></tr></thead>
                    <tbody>
                    @foreach($folio->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->quantity }}</td>
                            <td>{{ Auth::user()->priceFormat($line->unit_price) }}</td>
                            <td>{{ Auth::user()->priceFormat($line->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @can('hotel.manage.folio')
            <hr>
            <h6>{{ __('Add charge to room bill') }}</h6>
            <p class="text-muted small">{{ __('Minibar, laundry, restaurant, or other extras post here and appear on the final bill at checkout.') }}</p>
            {!! Form::open(['route' => ['hotel.folios.lines.store', $folio->id], 'method' => 'POST', 'class' => 'row g-2']) !!}
            <div class="col-md-3">
                {!! Form::label('charge_type', __('Charge type')) !!}
                {!! Form::select('charge_type', [
                    'minibar' => __('Minibar'),
                    'laundry' => __('Laundry'),
                    'misc' => __('Other extra'),
                ], 'misc', ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-4">
                {!! Form::label('description', __('Description')) !!}
                {!! Form::text('description', null, ['class' => 'form-control', 'required', 'maxlength' => '191']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('quantity', __('Qty')) !!}
                {!! Form::number('quantity', 1, ['class' => 'form-control', 'min' => '1']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('unit_price', __('Unit')) !!}
                {!! Form::number('unit_price', null, ['class' => 'form-control', 'step' => '0.01', 'required', 'min' => '0']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('tax_amount', __('Tax')) !!}
                {!! Form::number('tax_amount', 0, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('discount_amount', __('Discount')) !!}
                {!! Form::number('discount_amount', 0, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
            </div>
            <div class="col-md-4">
                {!! Form::label('revenue_channel', __('Revenue channel')) !!}
                {!! Form::select('revenue_channel', [
                    '' => __('Default by line type'),
                    'room' => __('Room'),
                    'restaurant' => __('Restaurant'),
                    'laundry' => __('Laundry'),
                    'misc' => __('Misc'),
                ], null, ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-secondary w-100">{{ __('Add') }}</button>
            </div>
            {!! Form::close() !!}
            @endcan

            <h6 class="mt-4">{{ __('Payments') }}</h6>
            @if($folio->payments->isEmpty())
                <p class="text-muted small">{{ __('No payments recorded yet.') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach($folio->payments as $p)
                            <tr>
                                <td>{{ $p->created_at }}</td>
                                <td>{{ $paymentMethods[$p->method] ?? $p->method }}</td>
                                <td>{{ Auth::user()->priceFormat($p->amount) }}</td>
                                <td>{{ $p->reference ?? '—' }}</td>
                                <td><a href="{{ route('hotel.folios.payments.receipt', [$folio->id, $p->id]) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ __('Receipt') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @can('hotel.manage.folio')
            <hr>
            <h6>{{ __('Record payment') }}</h6>
            {!! Form::open(['route' => ['hotel.folios.payments.store', $folio->id], 'method' => 'POST', 'class' => 'row g-2']) !!}
            <div class="col-md-3">
                {!! Form::label('amount', __('Amount')) !!}
                {!! Form::number('amount', null, ['class' => 'form-control', 'step' => '0.01', 'required', 'min' => '0.01']) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('method', __('Method')) !!}
                {!! Form::select('method', $paymentMethods, 'cash', ['class' => 'form-control', 'required']) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('reference', __('Reference')) !!}
                {!! Form::text('reference', null, ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">{{ __('Add payment') }}</button>
            </div>
            <div class="col-12">
                {!! Form::label('notes', __('Notes')) !!}
                {!! Form::text('notes', null, ['class' => 'form-control']) !!}
            </div>
            {!! Form::close() !!}
            @endcan
        </div></div>
    </div>
</div>
@endsection
