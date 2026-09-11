@extends('layouts.admin')
@section('page-title')
    {{ __('Purchase detail') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.transactions') }}">{{ __('Product transactions') }}</a></li>
    <li class="breadcrumb-item">{{ __('Purchase') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="window.print(); return false;" class="btn btn-sm btn-primary-subtle" title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4>{{ __('Purchase') }} {{ \Auth::user()->purchaseNumberFormat($doc->purchase_id) }}</h4>
                    <p class="mb-0 text-muted">{{ __('Date') }}: {{ $doc->purchase_date }} · {{ __('Supplier') }}: {{ $doc->vender->name ?? '—' }}</p>
                </div>
                <a href="{{ route('purchase.show', \Crypt::encrypt($doc->id)) }}" class="btn btn-sm btn-primary">{{ __('Open purchase') }}</a>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th class="text-end">{{ __('Qty') }}</th>
                        <th class="text-end">{{ __('Unit price') }}</th>
                        <th class="text-end">{{ __('Discount') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($doc->items as $line)
                        <tr>
                            <td>{{ $line->product->name ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $line->quantity, 2) }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat((float) $line->price) }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat((float) $line->discount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
