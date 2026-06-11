@extends('layouts.admin')
@section('page-title'){{ __('Minibar catalog') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Minibar catalog') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.rooms')
<a href="{{ route('hotel.minibar-items.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add item') }}</a>
{!! Form::open(['route' => 'hotel.minibar-items.seed-defaults', 'method' => 'POST', 'class' => 'd-inline']) !!}
<button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Load defaults') }}</button>
{!! Form::close() !!}
@endcan
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead>
        <tr>
            <th>{{ __('Name') }}</th>
            <th>{{ __('Price') }}</th>
            <th>{{ __('Default stock') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Action') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($items as $item)
        <tr>
            <td><strong>{{ $item->name }}</strong></td>
            <td>{{ Auth::user()->priceFormat($item->unit_price) }}</td>
            <td>{{ $item->default_quantity ?? 1 }}</td>
            <td>{{ $item->is_active ? __('Active') : __('Inactive') }}</td>
            <td>
                @can('hotel.manage.rooms')
                <a class="btn btn-sm btn-info" href="{{ route('hotel.minibar-items.edit', $item->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.minibar-items.destroy', $item->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                {!! Form::close() !!}
                @else
                <span class="text-muted small">{{ __('View only') }}</span>
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="text-muted">{{ __('No minibar items yet.') }} @can('hotel.manage.rooms')<a href="{{ route('hotel.minibar-items.create') }}">{{ __('Add item') }}</a>@endcan</td></tr>
    @endforelse
    </tbody>
</table>
</div></div></div>
@endsection
