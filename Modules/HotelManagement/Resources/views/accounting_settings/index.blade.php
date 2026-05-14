@extends('layouts.admin')
@section('page-title'){{ __('Hotel accounting (COA)') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Hotel accounting (COA)') }}</li>
@endsection
@section('content')
@if(!\Illuminate\Support\Facades\Schema::hasTable('hotel_accounting_settings'))
    <div class="alert alert-warning">{{ __('Run database migrations to enable hotel accounting settings.') }}</div>
@else
<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">{{ __('Map chart of account IDs for guest folio finalization (invoice journal) and folio payments. VAT uses folio tax_total when set; otherwise net is derived from folio lines.') }}</p>
        {!! Form::open(['route' => 'hotel.accounting-settings.update', 'method' => 'POST', 'class' => 'row g-3']) !!}
        <div class="col-md-6">
            {!! Form::label('chart_guest_ar_id', __('Guest accounts receivable')) !!}
            {!! Form::select('chart_guest_ar_id', $chartGuestArOptions, $setting->chart_guest_ar_id, ['class' => 'form-control']) !!}
            <small class="form-text text-muted">{{ __('Asset accounts (e.g. guest / trade receivable).') }}</small>
        </div>
        <div class="col-md-6">
            {!! Form::label('chart_vat_payable_id', __('VAT payable')) !!}
            {!! Form::select('chart_vat_payable_id', $chartVatPayableOptions, $setting->chart_vat_payable_id, ['class' => 'form-control']) !!}
            <small class="form-text text-muted">{{ __('Liability accounts (output VAT / sales tax payable).') }}</small>
        </div>
        <div class="col-md-6">
            {!! Form::label('chart_room_revenue_id', __('Room revenue')) !!}
            {!! Form::select('chart_room_revenue_id', $chartRevenueOptions, $setting->chart_room_revenue_id, ['class' => 'form-control']) !!}
        </div>
        <div class="col-md-6">
            {!! Form::label('chart_restaurant_revenue_id', __('Restaurant / F&B revenue')) !!}
            {!! Form::select('chart_restaurant_revenue_id', $chartRevenueOptions, $setting->chart_restaurant_revenue_id, ['class' => 'form-control']) !!}
        </div>
        <div class="col-md-6">
            {!! Form::label('chart_laundry_revenue_id', __('Laundry revenue')) !!}
            {!! Form::select('chart_laundry_revenue_id', $chartRevenueOptions, $setting->chart_laundry_revenue_id, ['class' => 'form-control']) !!}
        </div>
        <div class="col-md-6">
            {!! Form::label('chart_misc_revenue_id', __('Misc revenue')) !!}
            {!! Form::select('chart_misc_revenue_id', $chartRevenueOptions, $setting->chart_misc_revenue_id, ['class' => 'form-control']) !!}
        </div>
        <div class="col-md-4">
            {!! Form::label('default_vat_rate_percent', __('Default VAT % (reference only)')) !!}
            {!! Form::number('default_vat_rate_percent', old('default_vat_rate_percent', $setting->default_vat_rate_percent ?? 16), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'max' => '100']) !!}
        </div>
        <div class="col-md-8 d-flex align-items-end">
            <div class="form-check">
                {!! Form::checkbox('pos_invoice_when_charge_to_room', 1, $setting->pos_invoice_when_charge_to_room, ['class' => 'form-check-input', 'id' => 'pos_inv_room']) !!}
                {!! Form::label('pos_invoice_when_charge_to_room', __('Create POS invoice immediately when charging to room (override default: defer to folio checkout)'), ['class' => 'form-check-label', 'for' => 'pos_inv_room']) !!}
            </div>
        </div>
        <div class="col-12"><hr><h6>{{ __('Folio payment → bank account') }}</h6></div>
        @foreach($paymentMethods as $pm)
            <div class="col-md-4">
                {!! Form::label('bank_map_'.$pm, __(ucfirst(str_replace('_', ' ', $pm)))) !!}
                {!! Form::select('bank_map['.$pm.']', ['' => __('—')] + $banks, $setting->folio_payment_bank_map[$pm] ?? null, ['class' => 'form-control']) !!}
            </div>
        @endforeach
        <div class="col-12">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
        {!! Form::close() !!}
    </div>
</div>
@endif
@endsection
