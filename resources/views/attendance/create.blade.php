{{Form::open(array('url'=>'attendanceemployee','method'=>'post', 'id' => 'attendance_create_form'))}}
<div class="modal-body attendance-form-modal px-4 py-4">
    <div class="row g-3">
        <div class="form-group col-lg-6 col-md-6 mb-0">
            {{Form::label('employee_id',__('Employee'), ['class' => 'form-label'])}}
            {{Form::select('employee_id',$employees,null,array('class'=>'form-control select2', 'required' => true))}}
            <div class="text-xs mt-2">
                {{ __('Create employee here.') }} <a href="{{ route('employee.index') }}"><b>{{ __('Create employee') }}</b></a>
            </div>
        </div>
        <div class="form-group col-lg-6 col-md-6 mb-0">
            {{Form::label('date',__('Date'), ['class' => 'form-label'])}}
            {{Form::date('date', date('Y-m-d'), array('class'=>'form-control', 'required' => true))}}
        </div>
        <div class="form-group col-12 mb-0">
            {{ Form::label('attendance_status', __('Status'), ['class' => 'form-label']) }}
            <div class="d-flex flex-wrap gap-3 mt-1">
                <div class="form-check">
                    <input class="form-check-input attendance-status-radio" type="radio" name="attendance_status" id="attendance_status_present" value="present" checked>
                    <label class="form-check-label" for="attendance_status_present">{{ __('Present') }}</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input attendance-status-radio" type="radio" name="attendance_status" id="attendance_status_absent" value="absent">
                    <label class="form-check-label" for="attendance_status_absent">{{ __('Absent') }}</label>
                </div>
            </div>
            <small class="text-muted d-block mt-2">{{ __('Mark Absent when the employee did not come; no clock times are required.') }}</small>
        </div>
        <div class="col-12 attendance-clock-fields mb-0">
            <div class="row g-3">
                <div class="form-group col-lg-6 col-md-6 mb-0">
                    {{Form::label('clock_in',__('Clock In'), ['class' => 'form-label'])}}
                    {{Form::time('clock_in',null,array('class'=>'form-control attendance-clock-input'))}}
                </div>
                <div class="form-group col-lg-6 col-md-6 mb-0">
                    {{Form::label('clock_out',__('Clock Out'), ['class' => 'form-label'])}}
                    {{Form::time('clock_out',null,array('class'=>'form-control attendance-clock-input'))}}
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer attendance-form-modal-footer px-4 py-3">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{__('Cancel')}}</button>
    {{Form::submit(__('Create'),array('class'=>'btn btn-primary'))}}
</div>
{{Form::close()}}
@include('attendance.partials.modal-form-styles')
@include('attendance.partials.status-toggle-script')
