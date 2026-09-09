@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Batch / Lot') }}</th>
                <th>{{ __('Warehouse') }}</th>
                <th>{{ __('Expiry date') }}</th>
                <th class="num">{{ __('Days overdue') }}</th>
                <th class="num">{{ __('Qty remaining') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                @php
                    $expiry = $r->expiry_date ? \Carbon\Carbon::parse($r->expiry_date) : null;
                    $daysOverdue = $expiry ? $expiry->diffInDays(\Carbon\Carbon::today()) : null;
                @endphp
                <tr>
                    <td>{{ $r->product_name }}</td>
                    <td>{{ $r->sku ?: '—' }}</td>
                    <td>{{ $r->batch_no ?: '—' }}</td>
                    <td>{{ $r->warehouse_name ?: '—' }}</td>
                    <td>{{ $expiry ? $expiry->format('Y-m-d') : '—' }}</td>
                    <td class="num">{{ $daysOverdue !== null ? $daysOverdue : '—' }}</td>
                    <td class="num">{{ number_format((float) $r->quantity_remaining, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">{{ __('No expired products found.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
