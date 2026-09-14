@extends('layouts.admin')
@section('page-title')
    {{ __('Purchase order from quotation') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('quotation.index') }}">{{ __('Quotation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to purchase order') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a purchase order from quotation') }}
                        <strong>{{ Auth::user()->quotationNumberFormat($quotation->quotation_id) }}</strong>.
                        {{ __('Select the supplier who will supply the goods. You can still convert to proforma or invoice separately.') }}
                    </p>
                    {{ Form::open(['route' => ['quotation.convert-purchase.store', \Crypt::encrypt($quotation->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    @include('partials.convert_source_items', [
                        'sourceLabel' => __('From Quotation'),
                        'sourceValue' => Auth::user()->quotationNumberFormat($quotation->quotation_id),
                        'sourceLines' => $quotation->items,
                    ])
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('vender_id', __('Vendor'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('vender_id', $venders, null, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('warehouse_id', $warehouses, $quotation->warehouse_id, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('purchase_date', __('Purchase date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('purchase_date', date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            @php
                                $cats = \App\Models\ProductServiceCategory::where('created_by', \Auth::user()->creatorId())
                                    ->where('type', 'expense')
                                    ->pluck('name', 'id');
                                $cats->prepend(__('Select Category'), '');
                            @endphp
                            {{ Form::select('category_id', $cats, $category?->id, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Convert to purchase order'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('quotation.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
