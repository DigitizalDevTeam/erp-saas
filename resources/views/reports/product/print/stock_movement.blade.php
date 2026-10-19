@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('#') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('ID') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Movement') }}</th>
                <th>{{ __('From') }}</th>
                <th>{{ __('To') }}</th>
                <th class="num">{{ __('Current stock') }}</th>
                <th>{{ __('Reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r->sort_date ? date('Y-m-d H:i', strtotime($r->sort_date)) : '—' }}</td>
                    <td>{{ $r->product_id }}</td>
                    <td>{{ $r->sku ?: '—' }}</td>
                    <td>{{ $r->description ?: '—' }}</td>
                    <td class="num">{{ number_format((float) $r->movement, 2) }}</td>
                    <td>{{ $r->from_warehouse }}</td>
                    <td>{{ $r->to_warehouse }}</td>
                    <td class="num">
                        @if($r->to_warehouse_stock !== null)
                            {{ number_format((float) $r->to_warehouse_stock, 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $r->reason }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="empty">{{ __('No stock movements found for the selected period.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
