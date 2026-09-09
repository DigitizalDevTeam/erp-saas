@extends('layouts.product_report_print')

@section('content')
    <h2>{{ __('Warehouse bins') }}</h2>
    @forelse($byLocation as $key => $group)
        @php
            $hdr = $group->first();
            $locParts = array_pad(explode('|', $key, 3), 3, '—');
        @endphp
        <h2>{{ __('Warehouse') }}: {{ $hdr->warehouse_name }} | {{ __('Row') }}: {{ $locParts[1] }} | {{ __('Shelf') }}: {{ $locParts[2] }}</h2>
        <table class="report">
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th>{{ __('SKU') }}</th>
                    <th class="num">{{ __('Qty in bin') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group as $r)
                    <tr>
                        <td>{{ $r->product_name }}</td>
                        <td>{{ $r->sku ?? '—' }}</td>
                        <td class="num">{{ number_format((float) ($r->quantity ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="report">
            <tbody>
                <tr><td class="empty">{{ __('No warehouse stock with row or shelf. Add row/shelf on a purchase line or when adding product stock.') }}</td></tr>
            </tbody>
        </table>
    @endforelse

    @if($byLocationFallback->count() > 0)
        <h2>{{ __('Product card location only (global qty)') }}</h2>
        @foreach($byLocationFallback as $key => $group)
            @php
                $fbParts = array_pad(explode('|', $key, 3), 3, '—');
            @endphp
            <h2>{{ __('Row') }}: {{ $fbParts[1] }} | {{ __('Shelf') }}: {{ $fbParts[2] }}</h2>
            <table class="report">
                <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('SKU') }}</th>
                        <th class="num">{{ __('System qty') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($group as $p)
                        <tr>
                            <td>{{ $p->name }}</td>
                            <td>{{ $p->sku ?? '—' }}</td>
                            <td class="num">{{ number_format((float) ($p->quantity ?? 0), 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
