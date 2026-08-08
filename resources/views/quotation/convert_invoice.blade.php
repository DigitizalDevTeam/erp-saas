@extends('layouts.admin')
@section('page-title')
    {{ __('Invoice from quotation') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('quotation.index') }}">{{ __('Quotation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to invoice') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a sales invoice directly from quotation') }}
                        <strong>{{ Auth::user()->quotationNumberFormat($quotation->quotation_id) }}</strong>.
                        {{ __('You can still convert this quotation to a proforma or purchase order separately.') }}
                    </p>
                    {{ Form::open(['route' => ['quotation.convert-invoice.store', \Crypt::encrypt($quotation->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            {{ Form::label('issue_date', __('Issue date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('issue_date', $quotation->quotation_date ?? date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-6 mb-3">
                            {{ Form::label('due_date', __('Due date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('due_date', date('Y-m-d', strtotime('+30 days')), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="col-md-12 mb-3">
                            {{ Form::label('category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            @php
                                $cats = \App\Models\ProductServiceCategory::where('created_by', \Auth::user()->creatorId())
                                    ->where(function ($q) {
                                        $q->where('type', 'income')->orWhere('type', 1);
                                    })
                                    ->pluck('name', 'id');
                                $cats->prepend(__('Select Category'), '');
                            @endphp
                            {{ Form::select('category_id', $cats, $category?->id ?? $quotation->category_id, ['class' => 'form-control select', 'required' => true]) }}
                        </div>
                        @include('invoice.partials.convert_po_fields', [
                            'quotationReference' => $quotationReference ?? Auth::user()->quotationNumberFormat($quotation->quotation_id),
                            'refNumber' => $quotationReference ?? Auth::user()->quotationNumberFormat($quotation->quotation_id),
                        ])
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Create invoice'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('quotation.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
