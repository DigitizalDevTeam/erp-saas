{!! Form::open(['route' => 'hotel.price-configurator.room-rates', 'method' => 'POST']) !!}
<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Room type rates') }}</strong></div>
    <div class="card-body p-0">
        @if($roomTypes->isEmpty())
            <p class="text-muted small p-3 mb-0">{{ __('No room types yet.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Room type') }}</th>
                    <th style="width:200px">{{ __('Nightly rate') }}</th>
                    <th style="width:200px">{{ __('Monthly rate') }}</th>
                </tr></thead>
                <tbody>
                @foreach($roomTypes as $roomType)
                    <tr>
                        <td>{{ $roomType->name }}</td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="room_types[{{ $roomType->id }}][nightly_rate]" value="{{ old('room_types.'.$roomType->id.'.nightly_rate', $roomType->nightly_rate ?? $roomType->base_price ?? 0) }}"></td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="room_types[{{ $roomType->id }}][monthly_rate]" value="{{ old('room_types.'.$roomType->id.'.monthly_rate', $roomType->monthly_rate ?? 0) }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Per-room overrides') }}</strong> <span class="text-muted small">{{ __('— blank follows the room type rate') }}</span></div>
    <div class="card-body p-0">
        @if($rooms->isEmpty())
            <p class="text-muted small p-3 mb-0">{{ __('No rooms yet.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Room') }}</th>
                    <th>{{ __('Room type') }}</th>
                    <th style="width:200px">{{ __('Nightly override') }}</th>
                    <th style="width:200px">{{ __('Monthly override') }}</th>
                </tr></thead>
                <tbody>
                @foreach($rooms as $room)
                    <tr>
                        <td>{{ $room->room_no }}</td>
                        <td>{{ $room->roomType?->name ?? '—' }}</td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="rooms[{{ $room->id }}][override_price]" value="{{ old('rooms.'.$room->id.'.override_price', $room->override_price) }}" placeholder="{{ __('Type rate') }}"></td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="rooms[{{ $room->id }}][monthly_override_price]" value="{{ old('rooms.'.$room->id.'.monthly_override_price', $room->monthly_override_price) }}" placeholder="{{ __('Type rate') }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
<button type="submit" class="btn btn-primary">{{ __('Save room rates') }}</button>
{!! Form::close() !!}
