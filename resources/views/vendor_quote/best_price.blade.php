@extends('layouts.admin')
@section('page-title')
    {{ __('Compare best price') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('vendor-quote.index') }}">{{ __('Vendor quotes') }}</a></li>
    <li class="breadcrumb-item">{{ __('Compare best price') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('vendor-quote.best-price') }}" class="row mb-3">
                        <div class="col-md-6">
                            {{ Form::select('product_id', $products, $product->id ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">{{ __('Compare') }}</button>
                        </div>
                    </form>
                    @if ($product)
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Supplier') }}</th>
                                        <th>{{ __('Quote') }}</th>
                                        <th>{{ __('Date') }}</th>
                                        <th>{{ __('Quantity') }}</th>
                                        <th>{{ __('Price') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($rows as $idx => $row)
                                        <tr class="{{ $idx === 0 ? 'table-success' : '' }}">
                                            <td>{{ $row->vender_name ?? '' }}</td>
                                            <td>{{ Auth::user()->vendorQuoteNumberFormat($row->purchase_quotation_id) }}</td>
                                            <td>{{ $row->quote_date ? \Carbon\Carbon::parse($row->quote_date)->format('Y-m-d') : '' }}</td>
                                            <td>{{ $row->quantity }}</td>
                                            <td>
                                                {{ \Auth::user()->priceFormat($row->price) }}
                                                @if ($idx === 0)
                                                    <span class="badge bg-success">{{ __('Lowest price') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">{{ __('No supplier quotes for this product yet.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
