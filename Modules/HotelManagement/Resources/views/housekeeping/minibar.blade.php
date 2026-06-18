@extends('layouts.admin')
@section('page-title'){{ __('Daily minibar & extras') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.housekeeping.index') }}">{{ __('Housekeeping') }}</a></li>
    <li class="breadcrumb-item">{{ __('Daily minibar') }}</li>
@endsection
@section('content')
<div class="alert alert-light border small mb-3">
    {{ __('Housekeeping checks each occupied room daily, records minibar or laundry usage, and posts charges to the guest room folio. At checkout, all folio lines appear on the final bill.') }}
</div>

@if(($products ?? collect())->isEmpty())
<div class="alert alert-info small">{{ __('No stockable products found. Add products in the Products module to record room consumption.') }}</div>
@endif

@if($canManage ?? false)
<div class="card shadow-sm mb-3">
    <div class="card-header"><h6 class="mb-0">{{ __('Post consumption') }}</h6></div>
    <div class="card-body">
        @if(($occupiedStays ?? collect())->isEmpty())
            <p class="text-muted mb-0">{{ __('No in-house guests today.') }}</p>
        @else
            <div class="mb-2">
                <label class="form-label small fw-semibold">{{ __('Room') }}</label>
                <select id="hk_room_select" class="form-control mb-3">
                    @foreach($occupiedStays as $stay)
                        @php $r = $stay->reservation->room ?? null; @endphp
                        <option value="{{ $r?->id }}">
                            {{ $r?->room_no ?? '—' }} — {{ trim(($stay->reservation->guest->first_name ?? '').' '.($stay->reservation->guest->last_name ?? '')) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div id="hk_consumption_form_host">
                @php $firstStay = $occupiedStays->first(); $firstRoom = $firstStay?->reservation?->room; @endphp
                @if($firstRoom)
                    @include('hotelmanagement::partials.consumption_form', [
                        'formId' => 'hk_minibar_form',
                        'formAction' => route('hotel.housekeeping.minibar.store'),
                        'roomId' => $firstRoom->id,
                        'chargeTypes' => [
                            'minibar' => __('Minibar / Room consumable'),
                            'laundry' => __('Laundry'),
                            'misc' => __('Other extra'),
                        ],
                        'products' => $products,
                        'defaultDate' => $today,
                    ])
                @endif
            </div>
        @endif
    </div>
</div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">{{ __('Occupied rooms') }}</h6></div>
            <div class="card-body table-border-style p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>{{ __('Room') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Folio') }}</th><th></th></tr></thead>
                        <tbody>
                        @forelse($occupiedStays as $stay)
                            @php
                                $room = $stay->reservation->room ?? null;
                                $guest = $stay->reservation->guest ?? null;
                            @endphp
                            <tr>
                                <td>{{ $room?->room_no ?? '—' }}</td>
                                <td>{{ trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) }}</td>
                                <td>
                                    @if($stay->folio)
                                        <a href="{{ route('hotel.folios.show', $stay->folio->id) }}">{{ $stay->folio->folio_no }}</a>
                                    @endif
                                </td>
                                <td>
                                    @if($canManage && $room)
                                        <a href="{{ route('hotel.rooms.consumption', $room->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Add') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center">{{ __('No in-house guests.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">{{ __('Posted today') }} ({{ $today }})</h6></div>
            <div class="card-body table-border-style p-0">
                <div class="table-responsive">
                    <table class="table mb-0 table-sm">
                        <thead><tr><th>{{ __('Room') }}</th><th>{{ __('Type') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead>
                        <tbody>
                        @forelse($todayLogs as $log)
                            <tr>
                                <td>{{ $log->room->room_no ?? '—' }}</td>
                                <td>{{ __(ucfirst($log->charge_type)) }}</td>
                                <td>{{ $log->description }}</td>
                                <td class="text-end">{{ Auth::user()->priceFormat($log->line_total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center">{{ __('No consumption logged today yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if(($pendingRestocks ?? collect())->isNotEmpty())
<div class="card shadow-sm mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">{{ __('Minibar restock checklist') }}</h6>
        <a href="{{ route('hotel.minibar-items.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Minibar catalog') }}</a>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('After checkout, rooms need minibar restocked to catalog default quantities.') }}</p>
        @foreach($pendingRestocks as $restock)
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <strong>{{ __('Room') }} {{ $restock->room->room_no ?? '—' }}</strong>
                    @if($canManage ?? false)
                    {!! Form::open(['route' => ['hotel.housekeeping.restock.complete', $restock->id], 'method' => 'POST', 'class' => 'd-flex gap-2 align-items-center']) !!}
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="{{ __('Notes') }}" style="max-width:200px;">
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Mark restocked') }}</button>
                    {!! Form::close() !!}
                    @endif
                </div>
                <ul class="small mb-0 ps-3">
                    @foreach($restock->lines as $line)
                        <li>{{ $line->minibarItem->name ?? '—' }} × {{ $line->expected_qty }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
@endif
@endsection

@if($canManage && ($occupiedStays ?? collect())->isNotEmpty())
@push('script-page')
<script>
(function () {
    var roomSel = document.getElementById('hk_room_select');
    if (!roomSel) return;
    roomSel.addEventListener('change', function () {
        var hidden = document.querySelector('input[name="hotel_room_id"]');
        if (hidden) hidden.value = roomSel.value;
    });
})();
</script>
@endpush
@endif
