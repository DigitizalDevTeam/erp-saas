@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Purchase #') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th class="num">{{ __('Qty') }}</th>
                <th class="num">{{ __('Unit price') }}</th>
                <th class="num">{{ __('Line total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                @php
                    $lineTotal = (float) $line->price * (float) $line->quantity - (float) $line->discount;
                @endphp
                <tr>
                    <td>{{ $line->purchase_date ? \Carbon\Carbon::parse($line->purchase_date)->format('Y-m-d') : '—' }}</td>
                    <td>{{ Auth::user()->purchaseNumberFormat($line->purchase_seq) }}</td>
                    <td>{{ $line->supplier_name ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat((float) $line->price) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($lineTotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('No purchase history for this product.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
