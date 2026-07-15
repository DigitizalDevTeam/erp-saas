<div class="row">
    <div class="col-md-4 form-group">{!! Form::label('hotel_room_id', __('Room')) !!}{!! Form::select('hotel_room_id', $rooms, $housekeeping->hotel_room_id ?? ($selectedRoomId ?? null), ['class' => 'form-control', 'required', 'disabled' => (bool) $housekeeping]) !!}
        @if($housekeeping)
            <input type="hidden" name="hotel_room_id" value="{{ $housekeeping->hotel_room_id }}">
        @endif
    </div>
    <div class="col-md-4 form-group">{!! Form::label('assigned_to', __('Assigned To')) !!}{!! Form::select('assigned_to', ['' => __('Unassigned')] + $staff->toArray(), $housekeeping->assigned_to ?? null, ['class' => 'form-control']) !!}</div>
    @if(!$housekeeping)
    <div class="col-md-4 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', ['pending' => __('Pending (dirty)'), 'in_progress' => __('In Progress'), 'done' => __('Done (mark ready)')], $housekeeping->status ?? 'pending', ['class' => 'form-control']) !!}</div>
    @else
    <div class="col-md-4 form-group">
        <label class="form-label">{{ __('Task status') }}</label>
        <input type="text" class="form-control" value="{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $housekeeping->status))) }}" readonly>
    </div>
    @endif
    <div class="col-md-12 form-group">{!! Form::label('notes', __('Notes / Issues')) !!}{!! Form::text('notes', $housekeeping->notes ?? null, ['class' => 'form-control']) !!}</div>
</div>
