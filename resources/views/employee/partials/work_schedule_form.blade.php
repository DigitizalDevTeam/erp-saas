@php
    $s = \App\Models\Utility::settings();
    $defaultType = $s['default_work_schedule_type'] ?? 'office_fixed';
    $defaultGrace = (int) ($s['default_grace_minutes'] ?? 0);
    $emp = $employee ?? null;
    $typeVal = old('work_schedule_type', $emp->work_schedule_type ?? $defaultType);
    $graceVal = old('grace_minutes', $emp->grace_minutes ?? $defaultGrace);
    $useCompany = (bool) old('attendance_use_company_default', $emp ? $emp->attendance_use_company_default : true);
    $ws = old('work_start_time', $emp && $emp->work_start_time ? \Carbon\Carbon::parse($emp->work_start_time)->format('H:i') : '');
    $we = old('work_end_time', $emp && $emp->work_end_time ? \Carbon\Carbon::parse($emp->work_end_time)->format('H:i') : '');
    $types = [
        \App\Services\EmployeeAttendanceScheduleService::TYPE_OFFICE => __('Office — fixed start/end'),
        \App\Services\EmployeeAttendanceScheduleService::TYPE_SHIFT => __('Shift-based (e.g. hotel)'),
        \App\Services\EmployeeAttendanceScheduleService::TYPE_FLEXIBLE => __('Flexible / site-based (e.g. construction)'),
    ];
@endphp
<div class="card emp_details w-100">
    <div class="card-header">
        <h6 class="mb-0">{{ __('Work schedule & attendance') }}</h6>
        <small class="text-muted">{{ __('Late / early leave use company default hours unless you set custom times below. Grace minutes extend the allowed clock-in time.') }}</small>
    </div>
    <div class="card-body employee-detail-edit-body">
        <div class="row">
            <div class="form-group col-md-6">
                {{ Form::label('work_schedule_type', __('Work type'), ['class' => 'form-label']) }}
                {{ Form::select('work_schedule_type', $types, $typeVal, ['class' => 'form-control', 'id' => 'work_schedule_type']) }}
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('grace_minutes', __('Grace time (minutes)'), ['class' => 'form-label']) }}
                {{ Form::number('grace_minutes', $graceVal, ['class' => 'form-control', 'min' => 0, 'max' => 1440, 'step' => 1]) }}
                <small class="text-muted">{{ __('Extra minutes after expected start before counting as late.') }}</small>
            </div>
            <div class="form-group col-md-12">
                <input type="hidden" name="attendance_use_company_default" value="0">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="attendance_use_company_default" id="attendance_use_company_default" value="1" {{ $useCompany ? 'checked' : '' }}>
                    <label class="form-check-label" for="attendance_use_company_default">{{ __('Use company default start/end times') }}</label>
                </div>
                <small class="text-muted d-block">{{ __('When off, set custom expected start and end for this employee (office & shift types).') }}</small>
            </div>
            <div class="form-group col-md-6" id="custom_work_hours_wrap">
                {{ Form::label('work_start_time', __('Expected start'), ['class' => 'form-label']) }}
                {{ Form::time('work_start_time', $ws, ['class' => 'form-control', 'id' => 'work_start_time']) }}
            </div>
            <div class="form-group col-md-6" id="custom_work_hours_wrap2">
                {{ Form::label('work_end_time', __('Expected end'), ['class' => 'form-label']) }}
                {{ Form::time('work_end_time', $we, ['class' => 'form-control', 'id' => 'work_end_time']) }}
            </div>
        </div>
    </div>
</div>
@include('employee.partials.weekly_shift_roster', ['employee' => $emp])
@push('script-page')
<script>
(function () {
    function toggleCustomHours() {
        var useCo = document.getElementById('attendance_use_company_default');
        var type = document.getElementById('work_schedule_type');
        var flex = type && type.value === '{{ \App\Services\EmployeeAttendanceScheduleService::TYPE_FLEXIBLE }}';
        var show = useCo && useCo.checked === false && !flex;
        ['custom_work_hours_wrap', 'custom_work_hours_wrap2'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.style.display = show ? 'block' : 'none';
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var u = document.getElementById('attendance_use_company_default');
        var t = document.getElementById('work_schedule_type');
        if (u) u.addEventListener('change', toggleCustomHours);
        if (t) t.addEventListener('change', toggleCustomHours);
        toggleCustomHours();
    });
})();
</script>
@endpush
