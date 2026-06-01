@extends('layouts.admin')
@section('page-title')
    {{ __('Room map') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a></li>
    <li class="breadcrumb-item">{{ __('Room map') }}</li>
@endsection
@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-0">{{ __('Room map') }}</h5>
                <small class="text-muted">{{ __('Click an occupied room to post minibar or extras; available rooms open a new booking.') }}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('hotel.rooms.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-list me-1"></i>{{ __('Show list') }}
                </a>
                @if($canManage ?? false)
                <a href="{{ route('hotel.reservations.create') }}" class="btn btn-sm btn-primary">
                    <i class="ti ti-plus me-1"></i>{{ __('New reservation') }}
                </a>
                @endif
            </div>
        </div>
        <div class="card-body">
            @include('hotelmanagement::partials.floor_grid', [
                'rooms' => $rooms,
                'interactive' => $canManage ?? false,
                'compact' => false,
            ])
        </div>
    </div>
@endsection
