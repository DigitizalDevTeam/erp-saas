<div class="row">
    <div class="col-md-4 form-group">{!! Form::label('hotel_room_id', __('Room')) !!}{!! Form::select('hotel_room_id', $rooms, $housekeeping->hotel_room_id ?? null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('assigned_to', __('Assigned To')) !!}{!! Form::select('assigned_to', ['' => __('Unassigned')] + $staff->toArray(), $housekeeping->assigned_to ?? null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', ['pending' => __('Pending'), 'in_progress' => __('In Progress'), 'done' => __('Done')], $housekeeping->status ?? 'pending', ['class' => 'form-control']) !!}</div>
    <div class="col-md-12 form-group">{!! Form::label('notes', __('Notes / Issues')) !!}{!! Form::text('notes', $housekeeping->notes ?? null, ['class' => 'form-control']) !!}</div>
</div>
