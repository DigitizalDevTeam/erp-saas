@extends('layouts.admin')
@section('page-title'){{ __('Folio') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item">{{ __('Folio') }}</li>
@endsection
@section('content')
@php
    $ps = $folio->paymentStatusLabel();
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="card shadow-sm"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">{{ $folio->folio_no }}</h5>
                    <p class="mb-1">{{ __('Guest') }}: {{ trim(($folio->stay->reservation->guest->first_name ?? '').' '.($folio->stay->reservation->guest->last_name ?? '')) }}</p>
                    <p class="mb-0">{{ __('Folio status') }}: {{ ucfirst($folio->status) }}</p>
                </div>
                <div class="text-end">
                    <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }} fs-6">{{ __('Payment') }}: {{ ucfirst($ps) }}</span>
                    <p class="mb-0 mt-2"><strong>{{ __('Grand total') }}:</strong> {{ Auth::user()->priceFormat($folio->grand_total) }}</p>
                    <p class="mb-0"><strong>{{ __('Paid') }}:</strong> {{ Auth::user()->priceFormat($folio->paid_total) }}</p>
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

            <h6 class="mt-4">{{ __('Payments') }}</h6>
            @if($folio->payments->isEmpty())
                <p class="text-muted small">{{ __('No payments recorded yet.') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th></tr></thead>
                        <tbody>
                        @foreach($folio->payments as $p)
                            <tr>
                                <td>{{ $p->created_at }}</td>
                                <td>{{ $paymentMethods[$p->method] ?? $p->method }}</td>
                                <td>{{ Auth::user()->priceFormat($p->amount) }}</td>
                                <td>{{ $p->reference ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(Auth::user()->can('manage hotel frontdesk') || Auth::user()->can('manage hotel'))
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
            @endif
        </div></div>
    </div>
</div>
@endsection
