@extends('layouts.admin')
@section('page-title'){{ __('Hotel Rooms') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Rooms') }}</li>
@endsection
@section('action-btn')
<a href="{{ route('hotel.rooms.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Room') }}</a>
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Room') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Price') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($rooms as $room)
        <tr>
            <td>{{ $room->room_no }}</td>
            <td>{{ $room->roomType->name ?? '-' }}</td>
            <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $room->status))) }}</td>
            <td>{{ $room->override_price ?? '-' }}</td>
            <td>
                <a class="btn btn-sm btn-info" href="{{ route('hotel.rooms.edit', $room->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.rooms.destroy', $room->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                {!! Form::close() !!}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
