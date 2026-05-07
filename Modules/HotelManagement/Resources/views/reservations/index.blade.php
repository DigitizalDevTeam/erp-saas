@extends('layouts.admin')
@section('page-title'){{ __('Reservations') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reservations') }}</li>
@endsection
@section('action-btn')
<a href="{{ route('hotel.reservations.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Reservation') }}</a>
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('No') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Dates') }}</th><th>{{ __('Status') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($reservations as $r)
        <tr>
            <td>{{ $r->reservation_no }}</td>
            <td>{{ trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')) }}</td>
            <td>{{ $r->room->room_no ?? '-' }}</td>
            <td>{{ $r->check_in_date }} - {{ $r->check_out_date }}</td>
            <td>{{ ucfirst(str_replace('_',' ',$r->status)) }}</td>
            <td>
                @php $folio = $r->stay?->folio; @endphp
                @if($folio)
                    @php $ps = $folio->paymentStatusLabel(); @endphp
                    <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }}">{{ ucfirst($ps) }}</span>
                    <a class="small" href="{{ route('hotel.folios.show', $folio->id) }}">{{ __('Folio') }}</a>
                @else
                    <span class="text-muted">—</span>
                @endif
            </td>
            <td>
                <a class="btn btn-sm btn-info" href="{{ route('hotel.reservations.edit', $r->id) }}"><i class="ti ti-pencil text-white"></i></a>
                @if($r->status !== 'checked_in' && $r->status !== 'cancelled')
                    {!! Form::open(['method' => 'POST', 'route' => ['hotel.frontdesk.check-in', $r->id], 'class' => 'd-inline']) !!}
                    <button class="btn btn-sm btn-success">{{ __('Check-in') }}</button>
                    {!! Form::close() !!}
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
