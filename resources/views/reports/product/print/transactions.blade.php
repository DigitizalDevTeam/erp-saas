@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Product') }}</th>
                <th>{{ __('IN / OUT') }}</th>
                <th class="num">{{ __('Qty') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Reference') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($movements as $m)
                <tr>
                    <td>{{ $m->at ? \Carbon\Carbon::parse($m->at)->format('Y-m-d H:i') : '—' }}</td>
                    <td>{{ $m->product_name }}</td>
                    <td>{{ strtoupper((string) $m->direction) }}</td>
                    <td class="num">{{ number_format((float) $m->quantity, 2) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $m->kind)) }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($m->reference, 80) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('No movements for the selected filters.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
