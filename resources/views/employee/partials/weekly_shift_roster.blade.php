@php
    use App\Services\EmployeeWeeklyShiftService;
    use App\Support\AttendanceShiftCatalog;
    $emp = $employee ?? null;
    $rosterByDay = $emp ? EmployeeWeeklyShiftService::rosterByDay($emp->id) : [];
    $dayNames = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
    $options = AttendanceShiftCatalog::rosterOptions();
@endphp
<div class="card emp_details w-100 mt-3" id="weekly_shift_roster_card" style="display: none;">
    <div class="card-header">
        <h6 class="mb-0">{{ __('Weekly shift timetable') }}</h6>
        <small class="text-muted">{{ __('Assign morning, afternoon, or night per day. Night shifts (e.g. 23:00–07:00) are saved correctly.') }}</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Day') }}</th>
                        <th>{{ __('Shift') }}</th>
                        <th>{{ __('Start') }}</th>
                        <th>{{ __('End') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($d = 1; $d <= 7; $d++)
                        @php
                            $row = $rosterByDay[$d] ?? null;
                            $code = old("weekly_shift.{$d}.shift_code", $row->shift_code ?? AttendanceShiftCatalog::OFF);
                            $st = old("weekly_shift.{$d}.start_time", $row && $row->start_time ? \Carbon\Carbon::parse($row->start_time)->format('H:i') : '');
                            $en = old("weekly_shift.{$d}.end_time", $row && $row->end_time ? \Carbon\Carbon::parse($row->end_time)->format('H:i') : '');
                        @endphp
                        <tr class="weekly-shift-row" data-day="{{ $d }}">
                            <td class="align-middle fw-semibold">{{ $dayNames[$d] }}</td>
                            <td>
                                <select name="weekly_shift[{{ $d }}][shift_code]" class="form-control form-control-sm weekly-shift-code">
                                    @foreach($options as $val => $label)
                                        <option value="{{ $val }}" {{ $code === $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="time" name="weekly_shift[{{ $d }}][start_time]" class="form-control form-control-sm weekly-custom-start" value="{{ $st }}" {{ $code === AttendanceShiftCatalog::CUSTOM ? '' : 'disabled' }}>
                            </td>
                            <td>
                                <input type="time" name="weekly_shift[{{ $d }}][end_time]" class="form-control form-control-sm weekly-custom-end" value="{{ $en }}" {{ $code === AttendanceShiftCatalog::CUSTOM ? '' : 'disabled' }}>
                                <input type="hidden" name="weekly_shift[{{ $d }}][crosses_midnight]" class="weekly-crosses-midnight" value="{{ ($row && $row->crosses_midnight) ? '1' : '0' }}">
                            </td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>
</div>
@push('script-page')
<script>
(function () {
    function toggleRosterCard() {
        var type = document.getElementById('work_schedule_type');
        var card = document.getElementById('weekly_shift_roster_card');
        if (!type || !card) return;
        card.style.display = type.value === '{{ \App\Services\EmployeeAttendanceScheduleService::TYPE_SHIFT }}' ? 'block' : 'none';
    }
    function toggleCustomRow(row) {
        var sel = row.querySelector('.weekly-shift-code');
        var custom = sel && sel.value === '{{ AttendanceShiftCatalog::CUSTOM }}';
        row.querySelectorAll('.weekly-custom-start, .weekly-custom-end').forEach(function (inp) {
            inp.disabled = !custom;
        });
        if (custom) {
            var st = row.querySelector('.weekly-custom-start').value;
            var en = row.querySelector('.weekly-custom-end').value;
            var hid = row.querySelector('.weekly-crosses-midnight');
            if (hid && st && en) hid.value = (st >= en) ? '1' : '0';
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        var t = document.getElementById('work_schedule_type');
        if (t) t.addEventListener('change', toggleRosterCard);
        toggleRosterCard();
        document.querySelectorAll('.weekly-shift-row').forEach(function (row) {
            var sel = row.querySelector('.weekly-shift-code');
            if (sel) sel.addEventListener('change', function () { toggleCustomRow(row); });
            row.querySelectorAll('.weekly-custom-start, .weekly-custom-end').forEach(function (inp) {
                inp.addEventListener('change', function () { toggleCustomRow(row); });
            });
            toggleCustomRow(row);
        });
    });
})();
</script>
@endpush
