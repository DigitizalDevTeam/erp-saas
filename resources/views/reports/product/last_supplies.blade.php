@extends('layouts.admin')
@section('page-title')
    {{ __('Last 5 supplies') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Last 5 supplies') }}</li>
@endsection

@section('content')
    @if(!request('print'))
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.last-supplies') }}" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Product') }} <span class="text-danger">*</span></label>
                    <select name="product_id" class="form-control" required>
                        <option value="">{{ __('Select product') }}</option>
                        @foreach($productList as $pid => $pname)
                            <option value="{{ $pid }}" {{ (string) request('product_id') === (string) $pid ? 'selected' : '' }}>{{ $pname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Show') }}</button>
                </div>
                @if($product && $lines->isNotEmpty())
                    <div class="col-auto ms-auto">
                        <a href="{{ route('reports.product.last-supplies', ['product_id' => $product->id, 'print' => 1]) }}" target="_blank" class="btn btn-outline-secondary"><i class="ti ti-printer"></i> {{ __('Print') }}</a>
                    </div>
                @endif
            </form>
        </div>
    </div>
    @endif

    @if(request('print'))
        @push('script-page')
            <script>window.onload = function () { window.print(); };</script>
        @endpush
    @endif

    @if($product)
        <div class="card">
            <div class="card-body">
                <h5>{{ $product->name }}</h5>
                <p class="text-muted small mb-3">{{ __('Last 5 purchase lines') }}</p>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Purchase #') }}</th>
                            <th>{{ __('Supplier') }}</th>
                            <th class="text-end">{{ __('Qty') }}</th>
                            <th class="text-end">{{ __('Unit price') }}</th>
                            <th class="text-end">{{ __('Line total') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($lines as $line)
                            @php
                                $lineTotal = (float) $line->price * (float) $line->quantity - (float) $line->discount;
                            @endphp
                            <tr>
                                <td>{{ $line->purchase_date ? \Carbon\Carbon::parse($line->purchase_date)->format('Y-m-d') : '—' }}</td>
                                <td>
                                    <a href="{{ route('reports.product.document', ['docType' => 'purchase', 'id' => $line->purchase_table_id]) }}">{{ \Auth::user()->purchaseNumberFormat($line->purchase_seq) }}</a>
                                </td>
                                <td>{{ $line->supplier_name ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $line->quantity, 2) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $line->price) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat($lineTotal) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">{{ __('No purchase history for this product.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
