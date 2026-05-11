@extends('layouts.admin')
@section('page-title'){{ __('Rate Plans') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Rate Plans') }}</li>
@endsection
@section('action-btn')
@if(Auth::user()->can('manage hotel room') || Auth::user()->can('manage hotel'))
<a href="{{ route('hotel.rate-plans.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Rate Plan') }}</a>
@endif
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Room Type') }}</th><th>{{ __('Nightly') }}</th><th>{{ __('Monthly') }}</th><th>{{ __('Mode') }}</th><th>{{ __('Active') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($ratePlans as $plan)
        <tr>
            <td>{{ $plan->name }}</td>
            <td>{{ $plan->roomType->name ?? '-' }}</td>
            <td>{{ $plan->rate }}</td>
            <td>{{ $plan->monthly_rate }}</td>
            <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $plan->billing_mode))) }}</td>
            <td>{{ $plan->is_active ? __('Yes') : __('No') }}</td>
            <td>
                @if(Auth::user()->can('manage hotel room') || Auth::user()->can('manage hotel'))
                <a class="btn btn-sm btn-info" href="{{ route('hotel.rate-plans.edit', $plan->id) }}"><i class="ti ti-pencil text-white"></i></a>
                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.rate-plans.destroy', $plan->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                {!! Form::close() !!}
                @else
                <span class="text-muted small">{{ __('View only') }}</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
