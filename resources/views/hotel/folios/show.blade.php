@extends('layouts.admin')
@section('page-title'){{ __('Folio') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item">{{ __('Folio') }}</li>
@endsection
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card"><div class="card-body">
            <h5>{{ $folio->folio_no }}</h5>
            <p class="mb-1">{{ __('Guest') }}: {{ trim(($folio->stay->reservation->guest->first_name ?? '').' '.($folio->stay->reservation->guest->last_name ?? '')) }}</p>
            <p class="mb-3">{{ __('Status') }}: {{ ucfirst($folio->status) }}</p>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>{{ __('Description') }}</th><th>{{ __('Qty') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Total') }}</th></tr></thead>
                    <tbody>
                    @foreach($folio->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->quantity }}</td>
                            <td>{{ $line->unit_price }}</td>
                            <td>{{ $line->line_total }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <h6 class="text-end">{{ __('Grand Total') }}: {{ $folio->grand_total }}</h6>
        </div></div>
    </div>
</div>
@endsection
