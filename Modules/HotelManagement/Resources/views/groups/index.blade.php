@extends('layouts.admin')
@section('page-title'){{ __('Group reservations') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Groups') }}</li>
@endsection
@section('action-btn')
<a href="{{ route('hotel.groups.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('New group') }}</a>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table">
    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Company') }}</th><th>{{ __('Reservations') }}</th><th></th></tr></thead>
    <tbody>
    @foreach($groups as $g)
        <tr>
            <td>{{ $g->name }}</td>
            <td>{{ $g->company_name ?? '—' }}</td>
            <td>{{ $g->reservations_count }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.groups.show', $g->id) }}">{{ __('View') }}</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
