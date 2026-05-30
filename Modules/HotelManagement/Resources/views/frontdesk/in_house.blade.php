@extends('layouts.admin')
@section('page-title'){{ __('Frontdesk') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Frontdesk') }}</li>
@endsection
@section('content')
@if(session('warning_folio_id'))
<div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>{{ session('error') }}</span>
    <a href="{{ route('hotel.folios.show', session('warning_folio_id')) }}" class="btn btn-sm btn-primary">{{ __('Pay room bill') }}</a>
</div>
@endif
<div class="alert alert-light border small mb-0">{{ __('Frontdesk workflow hint') }}</div>
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0">{{ __('Room map') }}</h5>
                    <small class="text-muted">{{ __('Quick at-a-glance status across all floors') }}</small>
                </div>
                <a href="{{ route('hotel.rooms.map') }}" class="btn btn-sm btn-outline-primary">
                    <i class="ti ti-arrows-maximize me-1"></i>{{ __('Open full map') }}
                </a>
            </div>
            <div class="card-body">
                @include('hotelmanagement::partials.floor_grid', [
                    'rooms' => $mapRooms ?? collect(),
                    'interactive' => $canManage ?? false,
                    'compact' => true,
                ])
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ __('In-house guests') }}</h5>
            </div>
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th><th>{{ __('Room bill') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Action') }}</th></tr></thead>
                        <tbody>
                        @foreach($stays as $stay)
                            <tr>
                                <td>{{ trim(($stay->reservation->guest->first_name ?? '').' '.($stay->reservation->guest->last_name ?? '')) }}</td>
                                <td>{{ $stay->reservation->room->room_no ?? '-' }}</td>
                                <td>{{ $stay->check_in_at }}</td>
                                <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $stay->status))) }}</td>
                                <td>
                                    @if($stay->folio)
                                        <a href="{{ route('hotel.folios.show', $stay->folio->id) }}">{{ $stay->folio->folio_no }}</a>
                                    @endif
                                </td>
                                <td>
                                    @if($stay->folio)
                                        @php $ps = $stay->folio->paymentStatusLabel(); @endphp
                                        <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }}">{{ __(ucfirst($ps)) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if($canManage ?? false)
                                    @if($stay->status !== 'checked_out')
                                        @php
                                            $folioPs = $stay->folio ? $stay->folio->paymentStatusLabel() : null;
                                        @endphp
                                        @if($stay->folio && $folioPs !== 'paid')
                                            <a href="{{ route('hotel.folios.show', $stay->folio->id) }}" class="btn btn-sm btn-primary me-1">{{ __('Pay room bill') }}</a>
                                            @if($canForceCheckout ?? false)
                                                <a href="{{ route('hotel.frontdesk.checkout', $stay->hotel_reservation_id) }}" class="btn btn-sm btn-outline-warning">
                                                    <i class="ti ti-logout me-1"></i>{{ __('Check-out') }}
                                                </a>
                                            @endif
                                        @else
                                            <a href="{{ route('hotel.frontdesk.checkout', $stay->hotel_reservation_id) }}" class="btn btn-sm btn-warning">
                                                <i class="ti ti-logout me-1"></i>{{ __('Check-out') }}
                                            </a>
                                        @endif
                                    @endif
                                    @else
                                    <span class="text-muted small">{{ __('View only') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
