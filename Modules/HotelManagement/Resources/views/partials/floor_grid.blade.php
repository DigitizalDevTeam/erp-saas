@php
    $rooms = $rooms ?? collect();
    $interactive = $interactive ?? true;
    $compact = $compact ?? false;
    $showLegend = $showLegend ?? true;

    if (!($rooms instanceof \Illuminate\Support\Collection)) {
        $rooms = collect($rooms);
    }

    $statusMeta = [
        'available'   => ['color' => '#10B981', 'label' => __('Available')],
        'occupied'    => ['color' => '#EF4444', 'label' => __('Occupied')],
        'reserved'    => ['color' => '#F59E0B', 'label' => __('Reserved')],
        'cleaning'    => ['color' => '#3B82F6', 'label' => __('Cleaning')],
        'maintenance' => ['color' => '#6B7280', 'label' => __('Maintenance')],
    ];

    $resolveStatus = function ($room) {
        $reservation = $room->relationLoaded('currentReservation') ? $room->currentReservation : null;
        if ($room->status === 'occupied' || ($reservation && $reservation->status === 'checked_in')) {
            return 'occupied';
        }
        if ($room->status === 'cleaning') {
            return 'cleaning';
        }
        if ($room->status === 'maintenance') {
            return 'maintenance';
        }
        if ($reservation && in_array($reservation->status, ['confirmed', 'pending'])) {
            return 'reserved';
        }
        return 'available';
    };

    $grouped = $rooms->groupBy(function ($room) {
        if ($room->relationLoaded('floor') && $room->floor) {
            return $room->floor->name;
        }
        $no = (string) $room->room_no;
        if (preg_match('/^\d+$/', $no)) {
            $n = (int) $no;
            if ($n >= 100) {
                return __('Floor') . ' ' . (int) floor($n / 100);
            }
            return __('Floor') . ' ' . (int) substr($no, 0, 1);
        }
        return __('Other');
    })->sortKeys();
@endphp

<div class="hotel-floor-grid {{ $compact ? 'is-compact' : '' }}">
    @if($showLegend)
        <div class="floor-grid-legend d-flex flex-wrap align-items-center gap-3 mb-3">
            @foreach($statusMeta as $key => $meta)
                <span class="d-inline-flex align-items-center small text-muted">
                    <span class="legend-dot me-1" style="background:{{ $meta['color'] }};"></span>
                    {{ $meta['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    @if($grouped->isEmpty())
        <p class="text-muted small mb-0">{{ __('No rooms to display.') }}</p>
    @else
        @foreach($grouped as $floor => $floorRooms)
            <div class="floor-block mb-3">
                <div class="floor-header d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0 text-muted small text-uppercase fw-bold">
                        {{ $floor }}
                    </h6>
                    <small class="text-muted">{{ count($floorRooms) }} {{ __('rooms') }}</small>
                </div>
                <div class="floor-rooms">
                    @foreach($floorRooms as $room)
                        @php
                            $status = $resolveStatus($room);
                            $meta = $statusMeta[$status] ?? $statusMeta['available'];
                            $reservation = $room->relationLoaded('currentReservation') ? $room->currentReservation : null;
                            $guest = $reservation && $reservation->relationLoaded('guest') ? $reservation->guest : null;
                            $hasGuestAlerts = $guest && (
                                filled($guest->allergies ?? null)
                                || filled($guest->preferences ?? null)
                                || filled($guest->drink_preferences ?? null)
                            );

                            $href = '#';
                            if ($interactive) {
                                if ($status === 'occupied' && $reservation && $reservation->stay && $reservation->stay->folio) {
                                    $href = route('hotel.folios.show', $reservation->stay->folio->id);
                                } elseif ($status === 'occupied') {
                                    $href = route('hotel.frontdesk.in-house');
                                } elseif ($status === 'available') {
                                    $href = route('hotel.reservations.create') . '?room_id=' . $room->id;
                                } elseif ($status === 'reserved' && $reservation) {
                                    $href = route('hotel.reservations.edit', $reservation->id);
                                } else {
                                    $href = route('hotel.rooms.edit', $room->id);
                                }
                            }

                            $tooltip = '';
                            $fmtDate = function ($v) {
                                if (!$v) return '';
                                if ($v instanceof \Carbon\CarbonInterface) return $v->toDateString();
                                return (string) $v;
                            };
                            if ($status === 'occupied' && $reservation) {
                                $tooltip = ($guest ? trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) . ' • ' : '')
                                    . __('In') . ': ' . $fmtDate($reservation->check_in_date)
                                    . ' / ' . __('Out') . ': ' . $fmtDate($reservation->check_out_date);
                            } elseif ($status === 'reserved' && $reservation) {
                                $tooltip = ($guest ? trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) . ' • ' : '')
                                    . __('Reserved') . ': ' . $fmtDate($reservation->check_in_date)
                                    . ' → ' . $fmtDate($reservation->check_out_date);
                            } else {
                                $tooltip = $meta['label'];
                            }
                        @endphp

                        @if($interactive)
                            <a href="{{ $href }}" class="floor-room text-decoration-none" title="{{ $tooltip }}" style="--rs:{{ $meta['color'] }};">
                        @else
                            <div class="floor-room" title="{{ $tooltip }}" style="--rs:{{ $meta['color'] }};">
                        @endif
                            <div class="floor-room-inner">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="room-no">{{ $room->room_no }}</span>
                                    <span>
                                        @if($guest && !empty($guest->is_vip))
                                            <i class="ti ti-star-filled text-warning" title="{{ __('VIP') }}"></i>
                                        @endif
                                        @if($hasGuestAlerts)
                                            <i class="ti ti-alert-triangle text-danger" title="{{ __('Guest alert') }}"></i>
                                        @endif
                                    </span>
                                </div>
                                <span class="room-status-pill" style="background: {{ $meta['color'] }};">{{ $meta['label'] }}</span>
                                @if(!$compact)
                                    @if($guest)
                                        <div class="room-guest small mt-1">
                                            <span class="guest-avatar">{{ strtoupper(substr($guest->first_name ?? '?', 0, 1)) }}</span>
                                            <span class="text-truncate">{{ trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) }}</span>
                                        </div>
                                        @if($reservation && ($reservation->adults || $reservation->children))
                                            <div class="text-muted small">
                                                {{ (int) $reservation->adults }} {{ __('Adults') }}@if($reservation->children) + {{ (int) $reservation->children }} {{ __('Children') }}@endif
                                            </div>
                                        @endif
                                    @elseif($room->relationLoaded('roomType') && $room->roomType)
                                        <div class="text-muted small mt-1 text-truncate">{{ $room->roomType->name }}</div>
                                    @endif
                                @endif
                            </div>
                        @if($interactive)
                            </a>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>

@once
    @push('css-page')
        <style>
            .hotel-floor-grid .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
            .hotel-floor-grid .floor-rooms {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 10px;
            }
            .hotel-floor-grid.is-compact .floor-rooms {
                grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
                gap: 6px;
            }
            .hotel-floor-grid .floor-room {
                display: block;
                color: inherit;
                border-radius: 8px;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-left: 4px solid var(--rs);
                transition: transform .15s ease, box-shadow .15s ease;
                overflow: hidden;
            }
            a.floor-room:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,.06); color: inherit; }
            .hotel-floor-grid .floor-room-inner { padding: 10px 12px; }
            .hotel-floor-grid.is-compact .floor-room-inner { padding: 6px 8px; }
            .hotel-floor-grid .room-no { font-size: 1.1rem; font-weight: 700; color: #111827; }
            .hotel-floor-grid.is-compact .room-no { font-size: .95rem; }
            .hotel-floor-grid .room-status-pill {
                display: inline-block;
                margin-top: 4px;
                color: #fff;
                font-size: 11px;
                line-height: 1;
                padding: 3px 6px;
                border-radius: 999px;
                font-weight: 600;
                text-transform: capitalize;
            }
            .hotel-floor-grid.is-compact .room-status-pill { font-size: 10px; padding: 2px 5px; }
            .hotel-floor-grid .guest-avatar {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: #f3f4f6;
                color: #374151;
                font-size: 11px;
                font-weight: 700;
                margin-right: 6px;
            }
            .hotel-floor-grid .room-guest { display: flex; align-items: center; }
            .hotel-floor-grid .floor-header h6 { letter-spacing: .04em; }
        </style>
    @endpush
@endonce
