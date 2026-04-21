@php
    $guest = $reservation?->guest;
    $selectedRoomType = $reservation->hotel_room_type_id ?? null;
    $selectedRoom = $reservation->hotel_room_id ?? null;
    $checkInAtValue = old('check_in_at', isset($reservation) && !empty($reservation?->check_in_at) ? \Carbon\Carbon::parse($reservation->check_in_at)->format('Y-m-d\TH:i') : null);
    $checkOutAtValue = old('check_out_at', isset($reservation) && !empty($reservation?->check_out_at) ? \Carbon\Carbon::parse($reservation->check_out_at)->format('Y-m-d\TH:i') : null);
@endphp
<div class="row">
    <div class="col-12 mb-2"><h6 class="mb-0">{{ __('Guest Details') }}</h6></div>
    <div class="col-md-4 form-group">{!! Form::label('first_name', __('First Name')) !!}{!! Form::text('first_name', $guest?->first_name, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('last_name', __('Last Name')) !!}{!! Form::text('last_name', $guest?->last_name, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('phone', __('Phone')) !!}{!! Form::text('phone', $guest?->phone, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('email', __('Email')) !!}{!! Form::email('email', $guest?->email, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('company_name', __('Company Name (Optional)')) !!}{!! Form::text('company_name', $guest?->company_name, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('id_number', __('ID / Passport No')) !!}{!! Form::text('id_number', $guest?->id_number, ['class' => 'form-control']) !!}</div>

    <div class="col-12 mt-3 mb-2"><h6 class="mb-0">{{ __('Stay & Room Details') }}</h6></div>
    <div class="col-md-4 form-group">{!! Form::label('hotel_room_type_id', __('Room Type')) !!}{!! Form::select('hotel_room_type_id', $roomTypes, $selectedRoomType, ['class' => 'form-control', 'id' => 'hotel_room_type_id', 'required']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('hotel_room_id', __('Room')) !!}{!! Form::select('hotel_room_id', ['' => __('Select Room')] + $rooms->toArray(), $selectedRoom, ['class' => 'form-control', 'id' => 'hotel_room_id']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('source', __('Source')) !!}{!! Form::select('source', ['manual' => 'Manual', 'walkin' => 'Walk-in', 'online' => 'Online', 'ota' => 'OTA'], $reservation->source ?? 'manual', ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('check_in_at', __('Check-in Date & Time')) !!}<input type="datetime-local" name="check_in_at" value="{{ $checkInAtValue }}" class="form-control" required></div>
    <div class="col-md-4 form-group">{!! Form::label('check_out_at', __('Check-out Date & Time')) !!}<input type="datetime-local" name="check_out_at" value="{{ $checkOutAtValue }}" class="form-control" required></div>
    <div class="col-md-4 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked In', 'checked_out' => 'Checked Out', 'cancelled' => 'Cancelled'], $reservation->status ?? 'pending', ['class' => 'form-control']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('adults', __('Adults')) !!}{!! Form::number('adults', $reservation->adults ?? 1, ['class' => 'form-control', 'min' => 1]) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('children', __('Children')) !!}{!! Form::number('children', $reservation->children ?? 0, ['class' => 'form-control', 'min' => 0]) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('rate_per_night', __('Rate/Night')) !!}{!! Form::number('rate_per_night', $reservation->rate_per_night ?? null, ['class' => 'form-control', 'step' => '0.01', 'required']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('discount_amount', __('Discount')) !!}{!! Form::number('discount_amount', $reservation->discount_amount ?? 0, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
    <div class="col-12 form-group">{!! Form::label('notes', __('Notes')) !!}{!! Form::text('notes', $reservation->notes ?? null, ['class' => 'form-control']) !!}</div>
</div>

@push('script-page')
<script>
    (function () {
        var roomsByType = @json($roomsByType ?? []);
        var roomSelect = document.getElementById('hotel_room_id');
        var typeSelect = document.getElementById('hotel_room_type_id');
        var selectedRoomId = @json((string) ($selectedRoom ?? ''));

        function renderRoomOptions() {
            if (!roomSelect || !typeSelect) return;
            var typeId = String(typeSelect.value || '');
            var list = roomsByType[typeId] || [];
            var html = '<option value="">{{ __("Select Room") }}</option>';
            list.forEach(function (room) {
                var selected = selectedRoomId && String(room.id) === String(selectedRoomId) ? ' selected' : '';
                html += '<option value="' + room.id + '"' + selected + '>Room ' + room.room_no + '</option>';
            });
            roomSelect.innerHTML = html;
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                selectedRoomId = '';
                renderRoomOptions();
            });
        }
        renderRoomOptions();
    })();
</script>
@endpush
