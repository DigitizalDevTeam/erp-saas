@extends('layouts.admin')
@section('page-title')
    {{ __('Debit Note Detail') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bill.index') }}">{{ __('Debit Note') }}</a></li>
    <li class="breadcrumb-item">{{ auth()->user()->debitNoteNumberFormat($note->uid) }}</li>
@endsection
@php
    $settings = Utility::settings();
@endphp
@push('css-page')
    <style>
        #card-element {
            border: 1px solid #a3afbb !important;
            border-radius: 10px !important;
            padding: 10px !important;
        }
    </style>
@endpush
@section('content')
<div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="invoice">
                        <div class="invoice-print">
                            <div class="row invoice-title mt-2">
                                <div class="col-xs-12 col-sm-12 col-nd-6 col-lg-6 col-12 d-flex gap-2 align-item-center">
                                    <h4 class="m-0">{{ __('Debit Note') }}</h4>
                                    <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="ti ti-printer"></i></button>
                                </div>
                                <div class="col-xs-12 col-sm-12 col-nd-6 col-lg-6 col-12 text-end">
                                    <h4 class="invoice-number">
                                        {{ auth()->user()->debitNoteNumberFormat($note->uid) }}</h4>
                                </div>
                                <div class="col-12">
                                    <hr>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col text-end">
                                    <div class="d-flex align-items-center justify-content-end">
                                        <div class="me-4">
                                            <small>
                                                <strong>{{ __('Date') }} :</strong><br>
                                                {{ auth()->user()->dateFormat($note->date) }}<br><br>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">

                                <div class="col">
                                    <small class="font-style">
                                        <strong>{{ __('Billed To') }} :</strong><br>
                                        @if (!empty($note->bill_data->billing_address?->name))
                                            {{ !empty($note->bill_data->billing_address?->name) ? $note->bill_data->billing_address?->name : '' }}<br>
                                            {{ !empty($note->bill_data->billing_address?->address) ? $note->bill_data->billing_address?->address : '' }}<br>
                                            {{ !empty($note->bill_data->billing_address?->billing_city) ? $note->bill_data->billing_address?->city : '' . ', ' }}<br>
                                            {{ !empty($note->bill_data->billing_address?->state) ? $note->bill_data->billing_address?->state : ''. ', ' }},
                                            {{ !empty($note->bill_data->billing_address?->zip) ? $note->bill_data->billing_address?->zip : '' }}<br>
                                            {{ !empty($note->bill_data->billing_address?->country) ? $note->bill_data->billing_address?->country : '' }}<br>
                                            {{ !empty($note->bill_data->billing_address?->phone) ? $note->bill_data->billing_address?->phone : '' }}<br>
                                            @if ($settings['vat_gst_number_switch'] == 'on')
                                                @if(!empty($settings['tax_type']) && !empty($settings['vat_number'])){{$settings['tax_type'].' '. __('Number')}} : {{$settings['vat_number']}} <br>@endif

                                                <strong>{{ __('Tax Number ') }} :
                                                </strong>{{ !empty($customer->tax_number) ? $customer->tax_number : '' }}
                                            @endif
                                        @else
                                            -
                                        @endif

                                    </small>
                                </div>

                                @if (App\Models\Utility::getValByName('shipping_display') == 'on')
                                    <div class="col ">
                                        <small>
                                            <strong>{{ __('Shipped To') }} :</strong><br>
                                            @if (!empty($note->bill_data->shipping_address?->name))
                                                {{ !empty($note->bill_data->shipping_address?->name) ? $note->bill_data->shipping_address?->name : '' }}<br>
                                                {{ !empty($note->bill_data->shipping_address?->address) ? $note->bill_data->shipping_address?->address : '' }}<br>
                                                {{ !empty($note->bill_data->shipping_address?->city) ? $note->bill_data->shipping_address?->city : '' . ', ' }}<br>
                                                {{ !empty($note->bill_data->shipping_address?->state) ? $note->bill_data->shipping_address?->state : '' . ', ' }},
                                                {{ !empty($note->bill_data->shipping_address?->zip) ? $note->bill_data->shipping_address?->zip : '' }}<br>
                                                {{ !empty($note->bill_data->shipping_address?->country) ? $note->bill_data->shipping_address?->country : '' }}<br>
                                                {{ !empty($note->bill_data->shipping_address?->phone) ? $note->bill_data->shipping_address?->phone : '' }}<br>
                                            @else
                                                -
                                            @endif
                                        </small>
                                    </div>
                                @endif
                                <div class="col">
                                    <div class="float-end mt-3">
                                        @if($settings['bill_qr_display'] == 'on')
                                        <small><strong>{{ __('Bill') }} :</strong><br></small>
                                        <a href="{{ route('bill.show', \Crypt::encrypt($note->bill_data->id)) }}">
                                        {!! DNS2D::getBarcodeHTML(
                                            route('bill.link.copy', \Illuminate\Support\Facades\Crypt::encrypt($note->bill_data->id)),
                                            'QRCODE',
                                            2,
                                            2,
                                        ) !!}
                                        </a>
                                        @endif
                                    </div>
                                </div>
                                
                            </div>
                            <div class="row mt-3">
                                    <small><strong>{{ __('Description') }}</strong></small>
                                    <div class="mt-2">
                                        {!! $note->description ?? 'N\A' !!}
                                    </div>
                            </div>
                            <div class="row mt-3">
                                    <small><strong>{{ __('Amount') }}</strong></small>
                                    <div class="mt-2">
                                        {{ \Auth::user()->priceFormat($note->amount) }}
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection