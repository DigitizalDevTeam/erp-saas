@extends('layouts.admin')
@section('page-title')
    {{ __('Vendor quotes') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Vendor quotes') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create purchase')
            <a href="#" data-size="lg" data-url="{{ route('vendor-quote.create') }}" data-ajax-popup="true"
                data-bs-toggle="tooltip" data-bs-original-title="{{__('Create')}}" data-title="{{ __('Vendor quote') }}" class="btn btn-sm btn-primary">
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
                                    <th>{{ __('Quote ID') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Supplier') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    @if (Gate::check('edit purchase') || Gate::check('delete purchase') || Gate::check('create purchase'))
                                        <th>{{ __('Action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($quotes as $quote)
                                    <tr>
                                        <td class="Id">
                                            <a href="{{ route('vendor-quote.show', \Crypt::encrypt($quote->id)) }}"
                                                class="btn btn-outline-primary">{{ Auth::user()->vendorQuoteNumberFormat($quote->purchase_quotation_id) }}</a>
                                        </td>
                                        <td>{{ Auth::user()->dateFormat($quote->quote_date) }}</td>
                                        <td>{{ !empty($quote->vender) ? $quote->vender->name : '' }}</td>
                                        <td>{{ !empty($quote->warehouse) ? $quote->warehouse->name : '' }}</td>
                                        @if (Gate::check('edit purchase') || Gate::check('delete purchase') || Gate::check('create purchase'))
                                            <td class="Action">
                                                <span>
                                                    @if (empty($quote->converted_purchase_proforma_id))
                                                        @can('create purchase')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('vendor-quote.convert-purchase-proforma', \Crypt::encrypt($quote->id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-primary"
                                                                    data-bs-toggle="tooltip" title="{{ __('Convert to purchase proforma') }}">
                                                                    <i class="ti ti-file-description text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @else
                                                        <div class="action-btn me-2">
                                                            <a href="{{ route('purchase-proforma.show', \Crypt::encrypt($quote->converted_purchase_proforma_id)) }}"
                                                                class="mx-3 btn btn-sm align-items-center bg-primary"
                                                                data-bs-toggle="tooltip" title="{{ __('View purchase proforma') }}">
                                                                <i class="ti ti-file text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endif
                                                    @if (empty($quote->converted_proforma_id))
                                                        @can('create quotation')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('vendor-quote.convert-proforma.form', \Crypt::encrypt($quote->id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-success"
                                                                    data-bs-toggle="tooltip" title="{{ __('Convert to sales proforma') }}">
                                                                    <i class="ti ti-file-invoice text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @else
                                                        <div class="action-btn me-2">
                                                            <a href="{{ route('proforma.show', \Crypt::encrypt($quote->converted_proforma_id)) }}"
                                                                class="mx-3 btn btn-sm align-items-center bg-success"
                                                                data-bs-toggle="tooltip" title="{{ __('View sales proforma') }}">
                                                                <i class="ti ti-file text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endif
                                                    @if (empty($quote->converted_purchase_id))
                                                        @can('create purchase')
                                                            <div class="action-btn me-2">
                                                                <a href="{{ route('vendor-quote.convert-purchase', \Crypt::encrypt($quote->id)) }}"
                                                                    class="mx-3 btn btn-sm align-items-center bg-warning"
                                                                    data-bs-toggle="tooltip" title="{{ __('Convert to purchase order') }}">
                                                                    <i class="ti ti-exchange text-white"></i>
                                                                </a>
                                                            </div>
                                                        @endcan
                                                    @else
                                                        <div class="action-btn me-2">
                                                            <a href="{{ route('purchase.show', \Crypt::encrypt($quote->converted_purchase_id)) }}"
                                                                class="mx-3 btn btn-sm align-items-center bg-warning"
                                                                data-bs-toggle="tooltip" title="{{ __('View purchase order') }}">
                                                                <i class="ti ti-file text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endif

                                                    @can('edit purchase')
                                                        <div class="action-btn me-2">
                                                            <a href="{{ route('vendor-quote.edit', \Crypt::encrypt($quote->id)) }}"
                                                                class="mx-3 btn btn-sm align-items-center bg-info"
                                                                data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                                <i class="ti ti-pencil text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endcan
                                                    @can('delete purchase')
                                                        <div class="action-btn ">
                                                            {!! Form::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['vendor-quote.destroy', $quote->id],
                                                                'class' => 'delete-form-btn',
                                                                'id' => 'delete-form-vq-' . $quote->id,
                                                            ]) !!}
                                                            <a href="#"
                                                                class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                                                                data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                                data-confirm="{{ __('Are You Sure?') . '|' . __('This action can not be undone. Do you want to continue?') }}"
                                                                data-confirm-yes="document.getElementById('delete-form-vq-{{ $quote->id }}').submit();">
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
