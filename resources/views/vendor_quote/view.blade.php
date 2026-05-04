@extends('layouts.admin')
@section('page-title')
    {{ __('Vendor quote') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('vendor-quote.index') }}">{{ __('Vendor quotes') }}</a></li>
    <li class="breadcrumb-item">{{ Auth::user()->vendorQuoteNumberFormat($quote->purchase_quotation_id) }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @if ((int) $quote->is_converted === 0)
            @can('create purchase')
                <a href="{{ route('vendor-quote.convert-purchase', \Crypt::encrypt($quote->id)) }}" class="btn btn-primary">{{ __('Convert to purchase order') }}</a>
            @endcan
        @else
            @can('show purchase')
                <a href="{{ route('purchase.show', \Crypt::encrypt($quote->converted_purchase_id)) }}" class="btn btn-primary">{{ __('View purchase order') }}</a>
            @endcan
        @endif
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>{{ __('Vendor') }}:</strong> {{ $vendor->name ?? '-' }}</p>
                            <p><strong>{{ __('Warehouse') }}:</strong> {{ $quote->warehouse->name ?? '-' }}</p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <p><strong>{{ __('Date') }}:</strong> {{ Auth::user()->dateFormat($quote->quote_date) }}</p>
                            <p><strong>{{ __('Total') }}:</strong> {{ Auth::user()->priceFormat($quote->getTotal()) }}</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Price') }}</th>
                                    <th>{{ __('Discount') }}</th>
                                    <th>{{ __('Tax') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($iteams as $line)
                                    @php $p = \App\Models\ProductService::find($line->product_id); @endphp
                                    <tr>
                                        <td>{{ $p->name ?? '-' }}</td>
                                        <td>{{ $line->quantity }}</td>
                                        <td>{{ Auth::user()->priceFormat($line->price) }}</td>
                                        <td>{{ Auth::user()->priceFormat($line->discount) }}</td>
                                        <td>{{ $line->tax }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat(($line->price * $line->quantity) - $line->discount) }}</td>
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
