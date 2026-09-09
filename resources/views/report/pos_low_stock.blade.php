@extends('layouts.admin')
@section('page-title')
    {{ __('Minimum Stock Alert Report') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Minimum Stock Alert') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Products with stock equal to or less than their Low stock alert at value.') }} {{ __('Default threshold') }}: <strong>{{ $globalThreshold }}</strong> {{ __('(for items without per-product setting)') }}</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('SKU') }}</th>
                                    <th class="text-end">{{ __('Quantity') }}</th>
                                    <th class="text-end">{{ __('Low stock alert at') }}</th>
                                    <th class="text-end">{{ __('Sale Price') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($products as $p)
                                    <tr>
                                        <td>{{ $p->name }}</td>
                                        <td>{{ $p->sku ?: '—' }}</td>
                                        <td class="text-end">{{ $p->quantity }}</td>
                                        <td class="text-end">{{ $p->low_stock_threshold !== null ? $p->low_stock_threshold : $globalThreshold }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($p->sale_price ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No products at or below the minimum stock alert.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
