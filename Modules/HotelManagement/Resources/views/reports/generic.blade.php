@extends('layouts.admin')
@section('page-title'){{ $title }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reports.index') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ $title }}</li>
@endsection

@php
    $fmt = function ($value, $type) {
        if ($value === null || $value === '') {
            return $type === 'money' || $type === 'number' ? $value : '—';
        }
        return match ($type) {
            'money' => \Auth::user()->priceFormat($value),
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%',
            'number' => is_numeric($value) ? number_format((float) $value) : $value,
            default => $value,
        };
    };
    $toneClass = [
        'primary' => 'text-primary', 'success' => 'text-success', 'warning' => 'text-warning',
        'danger' => 'text-danger', 'info' => 'text-info', 'secondary' => 'text-secondary',
    ];
@endphp

@section('content')
@include('hotelmanagement::reports._nav')

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="avtar avtar-s bg-light-primary text-primary rounded"><i class="{{ $icon }} f-20"></i></span>
            <div>
                <h5 class="mb-0">{{ $title }}</h5>
                @if($subtitle)<small class="text-muted">{{ $subtitle }}</small>@endif
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-success" href="{{ request()->fullUrlWithQuery(['export' => 'excel']) }}">
                <i class="ti ti-file-spreadsheet me-1"></i>{{ __('Excel') }}
            </a>
            <a class="btn btn-sm btn-outline-danger" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}">
                <i class="ti ti-file-text me-1"></i>{{ __('PDF') }}
            </a>
        </div>
    </div>
    <div class="card-body">
        @if($showDateFilter)
            <div class="bg-light rounded p-2 mb-3">
                @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
                <small class="text-muted">{{ __('Period') }}: {{ $from->toDateString() }} → {{ $to->toDateString() }}</small>
            </div>
        @endif

        @if(!empty($summary))
            <div class="row g-2 mb-3">
                @foreach($summary as $stat)
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.04em;">{{ $stat['label'] }}</div>
                            <div class="h4 mb-0 mt-1 {{ $toneClass[$stat['tone'] ?? 'secondary'] ?? 'text-secondary' }}">{{ $stat['value'] }}</div>
                            @if(!empty($stat['hint']))<small class="text-muted">{{ $stat['hint'] }}</small>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach($columns as $col)
                            <th class="text-{{ $col['align'] ?? ($col['type'] ?? 'text') === 'money' || ($col['type'] ?? '') === 'number' || ($col['type'] ?? '') === 'percent' ? 'end' : 'start' }}">{{ $col['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            @foreach($columns as $col)
                                @php $type = $col['type'] ?? 'text'; $align = $col['align'] ?? (in_array($type, ['money','number','percent']) ? 'end' : 'start'); @endphp
                                <td class="text-{{ $align }}">{!! $fmt($row[$col['key']] ?? null, $type) !!}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) }}" class="text-center text-muted py-4">{{ $emptyText }}</td></tr>
                    @endforelse
                </tbody>
                @if($totalsRow)
                    <tfoot>
                        <tr class="fw-bold border-top">
                            @foreach($columns as $col)
                                @php $type = $col['type'] ?? 'text'; $align = $col['align'] ?? (in_array($type, ['money','number','percent']) ? 'end' : 'start'); @endphp
                                <td class="text-{{ $align }}">{!! array_key_exists($col['key'], $totalsRow) ? $fmt($totalsRow[$col['key']], $type) : '' !!}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <p class="text-muted small mt-3 mb-0">{{ __('Rows') }}: {{ count($rows) }}</p>
    </div>
</div>
@endsection
