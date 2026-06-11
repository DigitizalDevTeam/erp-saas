@extends('layouts.admin')
@section('page-title'){{ __('Guests') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Guests') }}</li>
@endsection
@section('content')
<div class="card shadow-sm mb-3"><div class="card-body">
    {!! Form::open(['method' => 'GET', 'class' => 'row g-2 align-items-end']) !!}
    <div class="col-md-5">
        {!! Form::label('q', __('Search')) !!}
        {!! Form::text('q', request('q'), ['class' => 'form-control', 'placeholder' => __('Name, email, phone, ID…')]) !!}
    </div>
    <div class="col-md-3">
        <div class="form-check mt-4">
            {!! Form::checkbox('vip', 1, request()->boolean('vip'), ['class' => 'form-check-input', 'id' => 'vip_filter']) !!}
            {!! Form::label('vip_filter', __('VIP only'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
    <div class="col-md-4">
        <button type="submit" class="btn btn-primary">{{ __('Search') }}</button>
        <a href="{{ route('hotel.guests.index') }}" class="btn btn-secondary">{{ __('Reset') }}</a>
    </div>
    {!! Form::close() !!}
</div></div>
<div class="card shadow-sm"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead>
        <tr>
            <th>{{ __('Guest') }}</th>
            <th>{{ __('Contact') }}</th>
            <th>{{ __('VIP') }}</th>
            <th>{{ __('Stays') }}</th>
            <th>{{ __('CRM') }}</th>
            <th>{{ __('Action') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($guests as $guest)
        <tr>
            <td>
                <a href="{{ route('hotel.guests.show', $guest->id) }}"><strong>{{ $guest->fullName() }}</strong></a>
                @if($guest->company_name)
                    <br><small class="text-muted">{{ $guest->company_name }}</small>
                @endif
            </td>
            <td>
                @if($guest->email)<div class="small">{{ $guest->email }}</div>@endif
                @if($guest->phone)<div class="small text-muted">{{ $guest->phone }}</div>@endif
            </td>
            <td>
                @if($guest->is_vip)
                    <span class="badge bg-warning text-dark">{{ __('VIP') }}</span>
                @else
                    —
                @endif
            </td>
            <td>{{ $guest->reservations_count }}</td>
            <td>
                @if($guest->customer_id)
                    <span class="badge bg-success">{{ __('Linked') }}</span>
                @else
                    <span class="text-muted small">{{ __('Not linked') }}</span>
                @endif
            </td>
            <td>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.guests.show', $guest->id) }}"><i class="ti ti-eye"></i></a>
                @if($canManage ?? false)
                <a class="btn btn-sm btn-info" href="{{ route('hotel.guests.edit', $guest->id) }}"><i class="ti ti-pencil text-white"></i></a>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-muted">{{ __('No guests found.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
{{ $guests->links() }}
</div></div>
@endsection
