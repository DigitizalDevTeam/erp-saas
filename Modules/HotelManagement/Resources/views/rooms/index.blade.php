@extends('layouts.admin')
@section('page-title'){{ __('Hotel Rooms') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Rooms') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.rooms')
<a href="{{ route('hotel.rooms.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Room') }}</a>
@endcan
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Unit') }}</th><th>{{ __('Floor') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Nightly') }}</th><th>{{ __('Monthly') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($rooms as $room)
        <tr>
            <td>{{ $room->room_no }}</td>
            <td>{{ $room->floor->name ?? '-' }}</td>
            <td>{{ $room->roomType->name ?? '-' }}</td>
            <td>
                @php
                    $statusClass = match ($room->status) {
                        'available' => 'success',
                        'occupied' => 'danger',
                        'dirty' => 'warning',
                        'cleaning' => 'info',
                        'maintenance' => 'secondary',
                        'blocked' => 'dark',
                        default => 'secondary',
                    };
                @endphp
                <span class="badge bg-{{ $statusClass }}">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $room->status))) }}</span>
            </td>
            <td>{{ $room->override_price ?? '-' }}</td>
            <td>{{ $room->monthly_override_price ?? '-' }}</td>
            <td>
                @can('hotel.manage.rooms')
                <a class="btn btn-sm btn-info" href="{{ route('hotel.rooms.edit', $room->id) }}" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                @if($room->status === 'blocked')
                    {!! Form::open(['method' => 'POST', 'route' => ['hotel.rooms.unblock', $room->id], 'class' => 'd-inline']) !!}
                    <button type="submit" class="btn btn-sm btn-success" title="{{ __('Unblock') }}"><i class="ti ti-lock-open"></i></button>
                    {!! Form::close() !!}
                @elseif(! in_array($room->status, ['occupied'], true))
                    <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#blockRoomModal{{ $room->id }}" title="{{ __('Block room') }}">
                        <i class="ti ti-lock"></i>
                    </button>
                    <div class="modal fade" id="blockRoomModal{{ $room->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                {!! Form::open(['method' => 'POST', 'route' => ['hotel.rooms.block', $room->id]]) !!}
                                <div class="modal-header">
                                    <h5 class="modal-title">{{ __('Block room') }} {{ $room->room_no }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted">{{ __('Blocked rooms cannot be assigned to reservations or used for check-in until unblocked.') }}</p>
                                    {!! Form::label('notes', __('Reason')) !!}
                                    {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('e.g. Guest request, VIP hold, out of inventory')]) !!}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                    <button type="submit" class="btn btn-dark">{{ __('Block room') }}</button>
                                </div>
                                {!! Form::close() !!}
                            </div>
                        </div>
                    </div>
                @endif
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.rooms.destroy', $room->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                {!! Form::close() !!}
                @else
                <span class="text-muted small">{{ __('View only') }}</span>
                @endcan
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
