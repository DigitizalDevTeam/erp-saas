@extends('layouts.admin')
@section('page-title')
    {{ __('Invoice from purchase order') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('purchase.index') }}">{{ __('Purchase') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to invoice') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a sales invoice from purchase order') }}
                        <strong>{{ Auth::user()->purchaseNumberFormat($purchase->purchase_id) }}</strong>.
                        {{ __('Customer and prices are taken from the linked proforma when available.') }}
                    </p>
                    {{ Form::open(['route' => ['purchase.convert-invoice.store', \Crypt::encrypt($purchase->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('customer_id', __('Customer'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('customer_id', $customers, $defaultCustomerId, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            @php
                                $cats = \App\Models\ProductServiceCategory::where('created_by', \Auth::user()->creatorId())
                                    ->where(function ($q) {
                                        $q->where('type', 'income')->orWhere('type', 1);
                                    })
                                    ->pluck('name', 'id');
                                $cats->prepend(__('Select Category'), '');
                            @endphp
                            {{ Form::select('category_id', $cats, $category?->id, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('issue_date', __('Issue date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('issue_date', $purchase->purchase_date ?? date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('due_date', __('Due date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('due_date', date('Y-m-d', strtotime('+30 days')), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-12 mb-3">
                            {{ Form::label('ref_number', __('Reference'), ['class' => 'form-label']) }}
                            {{ Form::text('ref_number', Auth::user()->purchaseNumberFormat($purchase->purchase_id), ['class' => 'form-control']) }}
                        </div>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Create invoice'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('purchase.show', \Crypt::encrypt($purchase->id)) }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
