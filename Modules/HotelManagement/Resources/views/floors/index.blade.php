@extends('layouts.admin')
@section('page-title'){{ __('Floors') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Floors') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.rooms')
<a href="{{ route('hotel.floors.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Floor') }}</a>
@endcan
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Level') }}</th><th>{{ __('Sort') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($floors as $floor)
        <tr>
            <td>{{ $floor->name }}</td>
            <td>{{ $floor->level_number ?? '-' }}</td>
            <td>{{ $floor->sort_order }}</td>
            <td>{{ $floor->is_active ? __('Active') : __('Inactive') }}</td>
            <td>
                @can('hotel.manage.rooms')
                <a class="btn btn-sm btn-info" href="{{ route('hotel.floors.edit', $floor->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.floors.destroy', $floor->id], 'class' => 'd-inline']) !!}
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
