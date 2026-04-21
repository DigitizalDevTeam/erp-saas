@extends('layouts.admin')
@section('page-title'){{ __('Frontdesk') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Frontdesk') }}</li>
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th><th>{{ __('Folio') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($stays as $stay)
        <tr>
            <td>{{ trim(($stay->reservation->guest->first_name ?? '').' '.($stay->reservation->guest->last_name ?? '')) }}</td>
            <td>{{ $stay->reservation->room->room_no ?? '-' }}</td>
            <td>{{ $stay->check_in_at }}</td>
            <td>{{ ucfirst(str_replace('_',' ',$stay->status)) }}</td>
            <td>
                @if($stay->folio)
                    <a href="{{ route('hotel.folios.show', $stay->folio->id) }}">{{ $stay->folio->folio_no }}</a>
                @endif
            </td>
            <td>
                @if($stay->status !== 'checked_out')
                {!! Form::open(['method' => 'POST', 'route' => ['hotel.frontdesk.check-out', $stay->id], 'class' => 'd-inline']) !!}
                <button class="btn btn-sm btn-warning">{{ __('Check-out') }}</button>
                {!! Form::close() !!}
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
