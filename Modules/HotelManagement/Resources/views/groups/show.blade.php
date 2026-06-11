@extends('layouts.admin')
@section('page-title'){{ $group->name }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.groups.index') }}">{{ __('Groups') }}</a></li>
    <li class="breadcrumb-item">{{ __('Detail') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
<a href="{{ route('hotel.groups.edit', $group->id) }}" class="btn btn-sm btn-primary"><i class="ti ti-pencil"></i> {{ __('Edit') }}</a>
{!! Form::open(['method' => 'DELETE', 'route' => ['hotel.groups.destroy', $group->id], 'class' => 'd-inline', 'onsubmit' => "return confirm('".__('Cancel all open reservations in this group?')."')"]) !!}
<button type="submit" class="btn btn-sm btn-danger"><i class="ti ti-ban"></i> {{ __('Cancel group') }}</button>
{!! Form::close() !!}
@endif
@endsection
@section('content')
<div class="card shadow-sm mb-3"><div class="card-body">
    <h5>{{ $group->name }}</h5>
    <p class="mb-1">{{ __('Company') }}: {{ $group->company_name ?? '—' }}</p>
    <p class="mb-1">{{ __('Contact') }}: {{ $group->contact_name ?? '—' }}</p>
    <p class="mb-0">{{ __('Notes') }}: {{ $group->notes ?? '—' }}</p>
</div></div>
<div class="card shadow-sm"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table table-sm">
    <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Dates') }}</th><th>{{ __('Rate') }}</th><th>{{ __('Payment') }}</th><th></th></tr></thead>
    <tbody>
    @foreach($group->reservations as $r)
        @php $folio = $r->stay?->folio; @endphp
        <tr>
            <td>{{ trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')) }}</td>
            <td>{{ $r->room->room_no ?? '—' }}</td>
            <td>{{ $r->check_in_date }} → {{ $r->check_out_date }}</td>
            <td>{{ Auth::user()->priceFormat($r->rate_per_night) }}</td>
            <td>
                @if($folio)
                    @php $ps = $folio->paymentStatusLabel(); @endphp
                    <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }}">{{ __(ucfirst($ps)) }}</span>
                @else
                    —
                @endif
            </td>
            <td>
                @if($canManage ?? false)
                <a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.reservations.show', $r->id) }}">{{ __('View') }}</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.reservations.edit', $r->id) }}">{{ __('Edit') }}</a>
                @endif
                @if($folio)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.folios.show', $folio->id) }}">{{ __('Folio') }}</a>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
