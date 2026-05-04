@extends('layouts.admin')
@section('page-title')
    {{ __('Invoice from proforma') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('proforma.index') }}">{{ __('Proforma') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create invoice') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Lines and amounts are taken from the proforma. Confirm dates and category, then create the sales invoice.') }}
                        <strong>{{ Auth::user()->proformaNumberFormat($proforma->proforma_id) }}</strong>
                    </p>
                    {{ Form::open(['route' => ['proforma.issue-invoice.store', \Crypt::encrypt($proforma->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('issue_date', __('Issue date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('issue_date', date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('due_date', __('Due date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('due_date', date('Y-m-d', strtotime('+30 days')), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-12 mb-3">
                            {{ Form::label('category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            @php
                                $cats = \App\Models\ProductServiceCategory::where('created_by', \Auth::user()->creatorId())->where('type', 'income')->pluck('name', 'id');
                                $cats->prepend(__('Select Category'), '');
                            @endphp
                            {{ Form::select('category_id', $cats, null, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-12 mb-3">
                            {{ Form::label('ref_number', __('Reference'), ['class' => 'form-label']) }}
                            {{ Form::text('ref_number', '', ['class' => 'form-control']) }}
                        </div>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Create invoice'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('proforma.show', \Crypt::encrypt($proforma->id)) }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
