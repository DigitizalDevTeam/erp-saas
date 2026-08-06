@extends('layouts.admin')
@section('page-title')
    {{ __('Proforma from vendor quote') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('vendor-quote.index') }}">{{ __('Vendor Quote') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to proforma') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a proforma invoice (agreed price) from this vendor quote. Choose the customer who will receive the proforma. You can still convert this vendor quote to a purchase order separately.') }}
                    </p>
                    {{ Form::open(['route' => ['vendor-quote.convert-proforma.store', \Crypt::encrypt($quote->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('customer_id', __('Customer'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('customer_id', $customers, null, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('proforma_date', __('Proforma date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('proforma_date', $quote->quote_date ?? date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Create proforma invoice'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('vendor-quote.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
