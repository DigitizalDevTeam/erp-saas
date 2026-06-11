@extends('layouts.admin')
@section('page-title'){{ __('Edit group') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.groups.index') }}">{{ __('Groups') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.groups.show', $group->id) }}">{{ $group->name }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm mb-3"><div class="card-body">
{!! Form::model($group, ['route' => ['hotel.groups.update', $group->id], 'method' => 'PUT']) !!}
<div class="row g-2 mb-3">
    <div class="col-md-4">{!! Form::label('name', __('Group name')) !!}{!! Form::text('name', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4">{!! Form::label('company_name', __('Company')) !!}{!! Form::text('company_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('contact_name', __('Contact person')) !!}{!! Form::text('contact_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-12">{!! Form::label('notes', __('Group notes')) !!}{!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
</div>
<button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
<a href="{{ route('hotel.groups.show', $group->id) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>

<div class="card shadow-sm"><div class="card-header"><h6 class="mb-0">{{ __('Member reservations') }}</h6></div>
<div class="card-body table-border-style p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
    <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Dates') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
    <tbody>
    @foreach($group->reservations as $r)
        <tr>
            <td>{{ $r->guest->fullName() }}</td>
            <td>{{ $r->room->room_no ?? '—' }}</td>
            <td>{{ $r->check_in_date }} → {{ $r->check_out_date }}</td>
            <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $r->status))) }}</td>
            <td>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.reservations.show', $r->id) }}">{{ __('View') }}</a>
                @if(!in_array($r->status, ['checked_out', 'cancelled']))
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.reservations.edit', $r->id) }}">{{ __('Edit') }}</a>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
<p class="text-muted small mt-2">{{ __('To add or remove members, edit individual reservations or create a new group.') }}</p>
@endsection
