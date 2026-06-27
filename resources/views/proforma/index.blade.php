@extends('layouts.admin')
@section('page-title')
    {{ __('Proforma invoices') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Proforma') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create quotation')
            <a href="#" data-size="lg" data-url="{{ route('proforma.create') }}" data-ajax-popup="true"
                data-bs-toggle="tooltip" data-bs-original-title="{{ __('Create') }}" data-title="{{ __('Proforma') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Proforma ID') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    @if (Gate::check('edit quotation') || Gate::check('delete quotation') || Gate::check('create purchase') || Gate::check('create invoice'))
                                        <th>{{ __('Action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($proformas as $proforma)
                                    <tr>
                                        <td class="Id">
                                            <a href="{{ route('proforma.show', \Crypt::encrypt($proforma->id)) }}"
                                                class="btn btn-outline-primary">{{ Auth::user()->proformaNumberFormat($proforma->proforma_id) }}</a>
                                        </td>
                                        <td>{{ Auth::user()->dateFormat($proforma->proforma_date) }}</td>
                                        <td>{{ ! empty($proforma->customer) ? $proforma->customer->name : '' }}</td>
                                        <td>{{ ! empty($proforma->warehouse) ? $proforma->warehouse->name : '' }}</td>

                                        @if (Gate::check('edit quotation') || Gate::check('delete quotation') || Gate::check('create purchase') || Gate::check('create invoice'))
                                            <td class="Action">
                                                <span>
                                                    @if ((int) $proforma->is_invoice_converted === 1)
                                                        @can('show invoice')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('invoice.show', \Crypt::encrypt($proforma->converted_invoice_id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-warning"
                                                                    data-bs-toggle="tooltip" title="{{ __('View invoice') }}">
                                                                    <i class="ti ti-file text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @elseif ((int) $proforma->is_purchase_converted === 1)
                                                        @can('show purchase')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('purchase.show', \Crypt::encrypt($proforma->converted_purchase_id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-primary"
                                                                    data-bs-toggle="tooltip" title="{{ __('View purchase order') }}">
                                                                    <i class="ti ti-shopping-cart text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @else
                                                        @can('create purchase')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('proforma.convert-purchase.form', \Crypt::encrypt($proforma->id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-warning"
                                                                    data-bs-toggle="tooltip" title="{{ __('Convert to purchase order') }}">
                                                                    <i class="ti ti-exchange text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @endif

                                                    @can('edit quotation')
                                                        <div class="action-btn me-2">
                                                            <a href="{{ route('proforma.edit', \Crypt::encrypt($proforma->id)) }}"
                                                                class="mx-3 btn btn-sm align-items-center bg-info"
                                                                data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                                <i class="ti ti-pencil text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endcan
                                                    @can('delete quotation')
                                                        <div class="action-btn ">
                                                            {!! Form::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['proforma.destroy', $proforma->id],
                                                                'class' => 'delete-form-btn',
                                                                'id' => 'delete-form-pf-' . $proforma->id,
                                                            ]) !!}
                                                            <a href="#"
                                                                class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                                                                data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                                data-confirm="{{ __('Are You Sure?') . '|' . __('This action can not be undone. Do you want to continue?') }}"
                                                                data-confirm-yes="document.getElementById('delete-form-pf-{{ $proforma->id }}').submit();">
                                                                <i class="ti ti-trash text-white"></i>
                                                            </a>
                                                            {!! Form::close() !!}
                                                        </div>
                                                    @endcan
                                                </span>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
