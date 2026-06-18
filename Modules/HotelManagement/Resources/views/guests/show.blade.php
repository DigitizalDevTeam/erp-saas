@extends('layouts.admin')
@section('page-title'){{ $guest->fullName() }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.guests.index') }}">{{ __('Guests') }}</a></li>
    <li class="breadcrumb-item">{{ __('Detail') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
<a href="{{ route('hotel.guests.edit', $guest->id) }}" class="btn btn-sm btn-primary"><i class="ti ti-pencil"></i> {{ __('Edit') }}</a>
@endif
@endsection
@section('content')
<div class="row">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3"><div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                    <i class="ti ti-user fs-4 text-muted"></i>
                </div>
                <div>
                    <h5 class="mb-0">{{ $guest->fullName() }}
                        @if($guest->is_vip)<span class="badge bg-warning text-dark ms-1">{{ __('VIP') }}</span>@endif
                    </h5>
                    @if($guest->company_name)<p class="text-muted small mb-0">{{ $guest->company_name }}</p>@endif
                </div>
            </div>
            <p class="mb-1"><strong>{{ __('Email') }}:</strong> {{ $guest->email ?? '—' }}</p>
            <p class="mb-1"><strong>{{ __('Phone') }}:</strong> {{ $guest->phone ?? '—' }}</p>
            <p class="mb-1"><strong>{{ __('Nationality') }}:</strong> {{ $guest->nationality ?? '—' }}</p>
            <p class="mb-1"><strong>{{ __('Country') }}:</strong> {{ $guest->country ?? '—' }}</p>
            <p class="mb-1"><strong>{{ __('ID') }}:</strong> {{ $guest->id_type ? __(ucfirst($guest->id_type)) . ': ' . $guest->id_number : '—' }}</p>
            <p class="mb-1"><strong>{{ __('Passport number') }}:</strong> {{ $guest->passport_number ?? '—' }}</p>
            <p class="mb-1"><strong>{{ __('Visa number') }}:</strong> {{ $guest->visa_number ?? '—' }}</p>
            @if($guest->allergies)
                <p class="mb-1"><strong>{{ __('Allergies') }}:</strong> {{ $guest->allergies }}</p>
            @endif
            @if($guest->preferences)
                <p class="mb-1"><strong>{{ __('Preferences') }}:</strong> {{ $guest->preferences }}</p>
            @endif
            @if($guest->drink_preferences)
                <p class="mb-0"><strong>{{ __('Drink preferences') }}:</strong> {{ $guest->drink_preferences }}</p>
            @endif
        </div></div>
        <div class="card shadow-sm"><div class="card-body">
            <h6>{{ __('CRM customer') }}</h6>
            @if($crmCustomer)
                <p class="mb-1">{{ $crmCustomer->name }}</p>
                <p class="text-muted small mb-0">#{{ $crmCustomer->customer_id }}</p>
            @else
                <p class="text-muted small">{{ __('Not linked to ERP customer.') }}</p>
                @if($canManage ?? false)
                {!! Form::open(['route' => ['hotel.guests.sync-crm', $guest->id], 'method' => 'POST']) !!}
                <button type="submit" class="btn btn-sm btn-outline-primary mt-2">{{ __('Link to CRM') }}</button>
                {!! Form::close() !!}
                @endif
            @endif
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm"><div class="card-header"><h6 class="mb-0">{{ __('Reservation history') }}</h6></div>
        <div class="card-body table-border-style p-0"><div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>{{ __('Reservation') }}</th>
                <th>{{ __('Dates') }}</th>
                <th>{{ __('Room') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Total') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($guest->reservations as $r)
                @php $folio = $r->stay?->folio; @endphp
                <tr>
                    <td>{{ $r->reservation_no }}</td>
                    <td>{{ $r->check_in_date }} → {{ $r->check_out_date }}</td>
                    <td>{{ $r->room->room_no ?? '—' }}</td>
                    <td><span class="badge bg-light text-dark">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $r->status))) }}</span></td>
                    <td>{{ $folio ? Auth::user()->priceFormat($folio->grand_total) : '—' }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.reservations.show', $r->id) }}">{{ __('View') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted p-3">{{ __('No reservations yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div></div></div>
    </div>
</div>
@endsection
