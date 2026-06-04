@extends('layouts.admin')
@section('page-title'){{ __('Room Types') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Room Types') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.rooms')
<a href="{{ route('hotel.room-types.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Room Type') }}</a>
@endcan
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Category') }}</th><th>{{ __('Nightly') }}</th><th>{{ __('Monthly') }}</th><th>{{ __('Default Billing') }}</th><th>{{ __('Max Occupancy') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($roomTypes as $type)
        <tr>
            <td>{{ $type->name }}</td>
            <td>{{ __(\Illuminate\Support\Str::title($type->unit_category ?? 'room')) }}</td>
            <td>{{ $type->nightly_rate ?? $type->base_price }}</td>
            <td>{{ $type->monthly_rate ?? 0 }}</td>
            <td>{{ __(\Illuminate\Support\Str::title($type->default_billing_mode ?? 'nightly')) }}</td>
            <td>{{ $type->max_occupancy }}</td>
            <td>{{ $type->is_active ? __('Active') : __('Inactive') }}</td>
            <td>
                @can('hotel.manage.rooms')
                <a class="btn btn-sm btn-info" href="{{ route('hotel.room-types.edit', $type->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.room-types.destroy', $type->id], 'class' => 'd-inline']) !!}
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
