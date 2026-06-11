@extends('layouts.admin')
@section('page-title'){{ __('Addons & extras') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Addons & extras') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.rooms')
<a href="{{ route('hotel.addons.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add addon') }}</a>
@endcan
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead>
        <tr>
            <th>{{ __('Name') }}</th>
            <th>{{ __('Type') }}</th>
            <th>{{ __('Price') }}</th>
            <th>{{ __('Billing mode') }}</th>
            <th>{{ __('Taxable') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Action') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($addons as $addon)
        <tr>
            <td>
                <strong>{{ $addon->name }}</strong>
                @if($addon->description)
                    <br><small class="text-muted">{{ $addon->description }}</small>
                @endif
            </td>
            <td>{{ \Modules\HotelManagement\Entities\HotelAddon::typeOptions()[$addon->type] ?? $addon->type }}</td>
            <td>{{ Auth::user()->priceFormat($addon->price) }}</td>
            <td>{{ \Modules\HotelManagement\Entities\HotelAddon::billingModeOptions()[$addon->billing_mode] ?? $addon->billing_mode }}</td>
            <td>{{ $addon->taxable ? __('Yes') : __('No') }}</td>
            <td>{{ $addon->is_active ? __('Active') : __('Inactive') }}</td>
            <td>
                @can('hotel.manage.rooms')
                <a class="btn btn-sm btn-info" href="{{ route('hotel.addons.edit', $addon->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.addons.destroy', $addon->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                {!! Form::close() !!}
                @else
                <span class="text-muted small">{{ __('View only') }}</span>
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-muted text-center">{{ __('No addons configured yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div></div></div>
@endsection
