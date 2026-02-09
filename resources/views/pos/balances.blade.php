@extends('layouts.admin')
@section('page-title')
    {{ __('pos.pos_balances') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{ __('pos.dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('pos.pos_balances') }}</li>
@endsection
@push('css-page')
    <link rel="stylesheet" href="{{ asset('css/datatable/buttons.dataTables.min.css') }}">
    <style>
        @media print {
            .no-print { display: none !important; }
            .breadcrumb, .page-title, nav.pagination { display: none !important; }
        }
    </style>
@endpush

@section('action-btn')
    @can('manage pos')
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="ti ti-printer"></i> {{ __('Print') }}
        </button>
    @endcan
@endsection
@section('content')
    <div id="printableArea">
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body table-border-style">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Opening Balance') }}</th>
                                    <th>{{ __('Closing Balance') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($shifts as $shift)
                                    <tr>
                                        <td>{{ $shift->opened_at ? $shift->opened_at->format('d M Y H:i') : '—' }}</td>
                                        <td>{{ $shift->user ? $shift->user->name : '—' }}</td>
                                        <td>{{ $shift->branch ? $shift->branch->name : '—' }}</td>
                                        <td>{{ Auth::user()->priceFormat($shift->opening_balance ?? 0) }}</td>
                                        <td>{{ $shift->closing_balance !== null && $shift->closing_balance !== '' ? Auth::user()->priceFormat($shift->closing_balance) : '—' }}</td>
                                        <td>
                                            @if($shift->status === \App\Models\PosShift::STATUS_OPEN)
                                                <span class="badge bg-success">{{ __('Open') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Closed') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-dark"><p>{{ __('pos.no_data_found_short') }}</p></td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if(method_exists($shifts, 'links'))
                            <div class="d-flex justify-content-end mt-3 no-print">
                                {{ $shifts->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
