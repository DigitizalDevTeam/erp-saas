@extends('layouts.admin')
@section('page-title')
    {{ __('Invoice detail') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.transactions') }}">{{ __('Product transactions') }}</a></li>
    <li class="breadcrumb-item">{{ __('Invoice') }}</li>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4>{{ __('Invoice') }} {{ \Auth::user()->invoiceNumberFormat($doc->invoice_id) }}</h4>
                    <p class="mb-0 text-muted">{{ __('Issue date') }}: {{ $doc->issue_date }} · {{ __('Customer') }}: {{ $doc->customer->name ?? '—' }}</p>
                </div>
                <a href="{{ route('invoice.show', \Crypt::encrypt($doc->id)) }}" class="btn btn-sm btn-primary">{{ __('Open in Sales') }}</a>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th class="text-end">{{ __('Qty') }}</th>
                        <th class="text-end">{{ __('Price') }}</th>
                        <th class="text-end">{{ __('Discount') }}</th>
                        <th>{{ __('Serial / MAC') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($doc->items as $line)
                        <tr>
                            <td>{{ $line->product->name ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $line->quantity, 2) }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat((float) $line->price) }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat((float) $line->discount) }}</td>
                            <td class="small">{!! !empty($line->identifiers) ? nl2br(e($line->identifiers)) : '—' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
