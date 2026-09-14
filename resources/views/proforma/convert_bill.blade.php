@extends('layouts.admin')
@section('page-title')
    {{ __('Supplier bill from proforma') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('proforma.index') }}">{{ __('Proforma') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to supplier bill') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a supplier invoice (bill) from proforma') }}
                        <strong>{{ Auth::user()->proformaNumberFormat($proforma->proforma_id) }}</strong>.
                        {{ __('Uncheck products you will not include on the bill.') }}
                    </p>
                    {{ Form::open(['route' => ['proforma.convert-bill.store', \Crypt::encrypt($proforma->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    @include('partials.convert_source_items', [
                        'sourceLabel' => __('From Proforma'),
                        'sourceValue' => Auth::user()->proformaNumberFormat($proforma->proforma_id),
                        'sourceLines' => $proforma->items,
                    ])
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('vender_id', __('Vendor'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('vender_id', $venders, null, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('bill_date', __('Bill date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('bill_date', $proforma->proforma_date?->format('Y-m-d') ?? date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('due_date', __('Due date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('due_date', date('Y-m-d', strtotime('+30 days')), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            @php
                                $cats = \App\Models\ProductServiceCategory::where('created_by', \Auth::user()->creatorId())->where('type', 'expense')->pluck('name', 'id');
                                $cats->prepend(__('Select Category'), '');
                            @endphp
                            {{ Form::select('category_id', $cats, $category?->id, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Convert to supplier bill'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('proforma.show', \Crypt::encrypt($proforma->id)) }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
