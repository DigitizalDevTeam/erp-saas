<?php

namespace App\Http\Controllers;

use App\Imports\AttendanceImport;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IpRestrict;
use App\Models\User;
use App\Models\Utility;
use App\Services\EmployeeAttendanceScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceEmployeeController extends Controller
{
    public function index(Request $request)
    {
        if (\Auth::user()->can('manage attendance')) {

            $branch = Branch::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branch->prepend('Select Branch', '');

            $department = Department::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $department->prepend('Select Department', '');

            if (\Auth::user()->type != 'client' && \Auth::user()->type != 'company') {

                $emp = !empty(\Auth::user()->employee) ? \Auth::user()->employee->id : 0;

                $attendanceEmployee = AttendanceEmployee::where('employee_id', $emp);

                if ($request->type == 'monthly' && !empty($request->month)) {
                    $month = date('m', strtotime($request->month));
                    $year = date('Y', strtotime($request->month));

                    $start_date = date($year . '-' . $month . '-01');
                    $end_date = date($year . '-' . $month . '-t');

                    $attendanceEmployee->whereBetween(
                        'date',
                        [
                            $start_date,
                            $end_date,
                        ]
                    );
                } elseif ($request->type == 'daily' && !empty($request->date)) {
                    $attendanceEmployee->where('date', $request->date);
                } else {
                    $month = date('m');
                    $year = date('Y');
                    $start_date = date($year . '-' . $month . '-01');
                    $end_date = date($year . '-' . $month . '-t');

                    $attendanceEmployee->whereBetween(
                        'date',
                        [
                            $start_date,
                            $end_date,
                        ]
                    );
                }
                $attendanceEmployee = $attendanceEmployee->get();

            } else {

                $employee = Employee::select('id')->where('created_by', \Auth::user()->creatorId());
                if (!empty($request->branch)) {
                    $employee->where('branch_id', $request->branch);
                }

                if (!empty($request->department)) {
                    $employee->where('department_id', $request->department);
                }

                $employee = $employee->get()->pluck('id');

                $attendanceEmployee = AttendanceEmployee::whereIn('employee_id', $employee);
                if ($request->type == 'monthly' && !empty($request->month)) {

                    $month = date('m', strtotime($request->month));
                    $year = date('Y', strtotime($request->month));
                    $start_date = date($year . '-' . $month . '-01');
                    $end_date = date('Y-m-t', strtotime('01-' . $month . '-' . $year));


                    $attendanceEmployee->whereBetween(
                        'date',
                        [
                            $start_date,
                            $end_date,
                        ]
                    );
                } elseif ($request->type == 'daily' && !empty($request->date)) {
                    $attendanceEmployee->where('date', $request->date);
                } else {

                    $month = date('m');
                    $year = date('Y');
                    $start_date = date($year . '-' . $month . '-01');
                    $end_date = date('Y-m-t', strtotime('01-' . $month . '-' . $year));


                    $attendanceEmployee->whereBetween(
                        'date',
                        [
                            $start_date,
                            $end_date,
                        ]
                    );
                }

                $attendanceEmployee = $attendanceEmployee->get();
            }

            return view('attendance.index', compact('attendanceEmployee', 'branch', 'department'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create()
    {
        if (\Auth::user()->can('create attendance')) {
            $employees = Employee::where('created_by', '=', Auth::user()->creatorId())
                ->orderBy('name')
                ->get()
                ->pluck('name', 'id');

            return view('attendance.create', compact('employees'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('create attendance')) {
            $isAbsent = $request->input('attendance_status') === 'absent';

            $rules = [
                'employee_id' => 'required',
                'date' => 'required|date',
                'attendance_status' => 'required|in:present,absent',
            ];
            if (! $isAbsent) {
                $rules['clock_in'] = 'required';
                $rules['clock_out'] = 'required';
            }

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }

            $existing = AttendanceEmployee::where('employee_id', $request->employee_id)
                ->where('date', $request->date)
                ->first();
            if ($existing) {
                return redirect()->route('attendanceemployee.index')->with('error', __('Employee Attendance Already Created.'));
            }

            $employeeAttendance = new AttendanceEmployee();
            $employeeAttendance->employee_id = $request->employee_id;
            $employeeAttendance->date = $request->date;
            $employeeAttendance->created_by = \Auth::user()->creatorId();

            if ($isAbsent) {
                $this->applyAbsentAttendance($employeeAttendance);
            } else {
                $date = $request->date;
                $inNorm = $this->normalizeAttendanceClock($request->clock_in);
                $outNorm = $this->normalizeAttendanceClock($request->clock_out);
                $metrics = $this->resolveEmployeeAttendanceMetrics((int) $request->employee_id, $date, $inNorm, $outNorm);
                $employeeAttendance->status = 'Present';
                $employeeAttendance->clock_in = $this->formatClockForStorage($request->clock_in);
                $employeeAttendance->clock_out = $this->formatClockForStorage($request->clock_out);
                $employeeAttendance->late = $metrics['late'];
                $employeeAttendance->early_leaving = $metrics['early_leaving'];
                $employeeAttendance->overtime = $metrics['overtime'];
                $employeeAttendance->total_rest = '00:00:00';
            }

            $employeeAttendance->save();

            return redirect()->route('attendanceemployee.index')->with('success', __('Employee attendance successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show()
    {
        return redirect()->route('attendanceemployee.index');
    }

    public function edit($id)
    {
        if (\Auth::user()->can('edit attendance')) {
            $attendanceEmployee = AttendanceEmployee::where('id', $id)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();
            if (! $attendanceEmployee) {
                return redirect()->back()->with('error', __('Attendance not found.'));
            }
            $employees = Employee::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            return view('attendance.edit', compact('attendanceEmployee', 'employees'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function update(Request $request, $id)
    {
        if (\Auth::user()->can('edit attendance') && $request->has('attendance_status')) {
            $attendance = AttendanceEmployee::where('id', $id)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();

            if (! $attendance) {
                return redirect()->back()->with('error', __('Attendance not found.'));
            }

            $duplicate = AttendanceEmployee::where('employee_id', $request->employee_id)
                ->where('date', $request->date)
                ->where('id', '!=', $id)
                ->first();
            if ($duplicate) {
                return redirect()->route('attendanceemployee.index')->with('error', __('Employee Attendance Already Created.'));
            }

            $isAbsent = $request->input('attendance_status') === 'absent';
            $rules = [
                'employee_id' => 'required',
                'date' => 'required|date',
                'attendance_status' => 'required|in:present,absent',
            ];
            if (! $isAbsent) {
                $rules['clock_in'] = 'required';
                $rules['clock_out'] = 'required';
            }
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }

            $attendance->employee_id = $request->employee_id;
            $attendance->date = $request->date;

            if ($isAbsent) {
                $this->applyAbsentAttendance($attendance);
            } else {
                $inNorm = $this->normalizeAttendanceClock($request->clock_in);
                $outNorm = $this->normalizeAttendanceClock($request->clock_out);
                $metrics = $this->resolveEmployeeAttendanceMetrics((int) $request->employee_id, $request->date, $inNorm, $outNorm);
                $attendance->status = 'Present';
                $attendance->clock_in = $this->formatClockForStorage($request->clock_in);
                $attendance->clock_out = $this->formatClockForStorage($request->clock_out);
                $attendance->late = $metrics['late'];
                $attendance->early_leaving = $metrics['early_leaving'];
                $attendance->overtime = $metrics['overtime'];
                $attendance->total_rest = '00:00:00';
            }

            $attendance->save();

            return redirect()->route('attendanceemployee.index')->with('success', __('Employee attendance successfully updated.'));
        }

        $employeeId = !empty(\Auth::user()->employee) ? \Auth::user()->employee->id : 0;
        $todayAttendance = AttendanceEmployee::where('employee_id', '=', $employeeId)->where('date', date('Y-m-d'))->first();
        //        if(!empty($todayAttendance) && $todayAttendance->clock_out == '00:00:00')
        //        if($todayAttendance->clock_out == '00:00:00')
        //        {

        if (Auth::user()->type == 'Employee') {

            $record = AttendanceEmployee::where('id', $id)->first();
            if (! $record) {
                return redirect()->back()->with('error', __('Attendance not found.'));
            }
            $date = $record->date;
            $time = date('H:i:s');
            $metrics = $this->resolveEmployeeAttendanceMetrics((int) $record->employee_id, $date, $this->normalizeAttendanceClock($record->clock_in), $time);

            $attendanceEmployee = [
                'clock_out' => $time,
                'early_leaving' => $metrics['early_leaving'],
                'overtime' => $metrics['overtime'],
                'late' => $metrics['late'],
            ];

            if (! empty($request->date)) {
                $attendanceEmployee['date'] = $request->date;
            }
            AttendanceEmployee::where('id', $id)->update($attendanceEmployee);

            return redirect()->route('hrm.dashboard')->with('success', __('Employee successfully clock Out.'));
        } else {
            $date = date('Y-m-d');
            $clockout_time = date('H:i:s');
            $attendanceEmployee = AttendanceEmployee::find($id);
            if (! $attendanceEmployee) {
                return redirect()->back()->with('error', __('Attendance not found.'));
            }
            $metrics = $this->resolveEmployeeAttendanceMetrics(
                (int) $attendanceEmployee->employee_id,
                $attendanceEmployee->date,
                $this->normalizeAttendanceClock($attendanceEmployee->clock_in),
                $clockout_time
            );

            $attendanceEmployee->clock_out = $clockout_time;
            $attendanceEmployee->late = $metrics['late'];
            $attendanceEmployee->early_leaving = $metrics['early_leaving'];
            $attendanceEmployee->overtime = $metrics['overtime'];
            $attendanceEmployee->total_rest = '00:00:00';

            $attendanceEmployee->save();

            return redirect()->back()->with('success', __('Employee attendance successfully updated.'));
        }
        //        }
        //        else
        //        {
        //            return redirect()->back()->with('error', __('Employee are not allow multiple time clock in & clock for every day.'));
        //        }
    }

    public function destroy($id)
    {
        if (\Auth::user()->can('delete attendance')) {
            $attendance = AttendanceEmployee::where('id', $id)->first();

            $attendance->delete();

            return redirect()->route('attendanceemployee.index')->with('success', __('Attendance successfully deleted.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function attendance(Request $request)
    {
        $settings = Utility::settings();

        if ($settings['ip_restrict'] == 'on') {
            $userIp = request()->ip();
            $ip = IpRestrict::where('created_by', \Auth::user()->creatorId())->whereIn('ip', [$userIp])->first();
            if (empty($ip)) {
                return redirect()->back()->with('error', __('This ip is not allowed to clock in & clock out.'));
            }
        }
        $employeeId = !empty(\Auth::user()->employee) ? \Auth::user()->employee->id : 0;

        $todayAttendance = AttendanceEmployee::where('employee_id', '=', $employeeId)->where('date', date('Y-m-d'))->orderBy('id', 'desc')->first();
        //        if(empty($todayAttendance))
        //        {

        // Find the last clocked out entry for the employee (same day — break / return from break)
        $lastClockOutEntry = AttendanceEmployee::orderBy('id', 'desc')
            ->where('employee_id', '=', $employeeId)
            ->where('clock_out', '!=', '00:00:00')
            ->where('date', '=', date('Y-m-d'))
            ->first();

        $date = date('Y-m-d');
        $time = date('H:i:s');

        if ($lastClockOutEntry != null) {
            $lastClockOutTime = $lastClockOutEntry->clock_out;
            $actualClockInTime = $date . ' ' . $time;

            $totalLateSeconds = strtotime($actualClockInTime) - strtotime($date . ' ' . $lastClockOutTime);

            $totalLateSeconds = max($totalLateSeconds, 0);

            $hours = abs(floor($totalLateSeconds / 3600));
            $mins = abs(floor($totalLateSeconds / 60 % 60));
            $secs = abs(floor($totalLateSeconds % 60));
            $late = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        } else {
            $emp = Employee::find($employeeId);
            $window = EmployeeAttendanceScheduleService::resolveWindow($emp, (int) \Auth::user()->creatorId());
            $late = EmployeeAttendanceScheduleService::computeLateAtClockIn($date, $time, $window);
        }

        $checkDb = AttendanceEmployee::where('employee_id', '=', \Auth::user()->id)->get()->toArray();

        if (empty($checkDb)) {
            $employeeAttendance = new AttendanceEmployee();
            $employeeAttendance->employee_id = $employeeId;
            $employeeAttendance->date = $date;
            $employeeAttendance->status = 'Present';
            $employeeAttendance->clock_in = $time;
            $employeeAttendance->clock_out = '00:00:00';
            $employeeAttendance->late = $late;
            $employeeAttendance->early_leaving = '00:00:00';
            $employeeAttendance->overtime = '00:00:00';
            $employeeAttendance->total_rest = '00:00:00';
            $employeeAttendance->created_by = \Auth::user()->id;

            $employeeAttendance->save();

            return redirect()->back()->with('success', __('Employee Successfully Clock In.'));
        }
        foreach ($checkDb as $check) {

            $employeeAttendance = new AttendanceEmployee();
            $employeeAttendance->employee_id = $employeeId;
            $employeeAttendance->date = $date;
            $employeeAttendance->status = 'Present';
            $employeeAttendance->clock_in = $time;
            $employeeAttendance->clock_out = '00:00:00';
            $employeeAttendance->late = $late;
            $employeeAttendance->early_leaving = '00:00:00';
            $employeeAttendance->overtime = '00:00:00';
            $employeeAttendance->total_rest = '00:00:00';
            $employeeAttendance->created_by = \Auth::user()->id;

            $employeeAttendance->save();

            return redirect()->back()->with('success', __('Employee Successfully Clock In.'));

        }
        //        }
        //        else
        //        {
        //            return redirect()->back()->with('error', __('Employee are not allow multiple time clock in & clock for every day.'));
        //        }
    }

    public function bulkAttendance(Request $request)
    {
        if (\Auth::user()->can('create attendance')) {

            $branch = Branch::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branch->prepend('Select Branch', '');

            $department = Department::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $department->prepend('Select Department', '');

            $employees = [];
            if (!empty($request->branch) && !empty($request->department)) {
                $employees = Employee::where('created_by', \Auth::user()->creatorId())->where('branch_id', $request->branch)->where('department_id', $request->department)->get();

            } else {
                $employees = Employee::where('created_by', \Auth::user()->creatorId())->where('branch_id', 1)->where('department_id', 1)->get();
            }

            return view('attendance.bulk', compact('employees', 'branch', 'department'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function bulkAttendanceData(Request $request)
    {

        if (\Auth::user()->can('create attendance')) {
            if (!empty($request->branch) && !empty($request->department)) {
                $date = $request->date;

                $employees = $request->employee_id;
                $atte = [];

                if (!empty($employees)) {
                    foreach ($employees as $employee) {
                        $present = 'present-' . $employee;
                        $in = 'in-' . $employee;
                        $out = 'out-' . $employee;
                        $atte[] = $present;
                        if ($request->$present == 'on') {

                            $in = date('H:i:s', strtotime($request->$in));
                            $out = date('H:i:s', strtotime($request->$out));

                            $metrics = $this->resolveEmployeeAttendanceMetrics((int) $employee, $date, $in, $out);
                            $late = $metrics['late'];
                            $earlyLeaving = $metrics['early_leaving'];
                            $overtime = $metrics['overtime'];
                            $attendance = AttendanceEmployee::where('employee_id', '=', $employee)->where('date', '=', $request->date)->first();

                            if (!empty($attendance)) {
                                $employeeAttendance = $attendance;
                            } else {
                                $employeeAttendance = new AttendanceEmployee();
                                $employeeAttendance->employee_id = $employee;
                                $employeeAttendance->created_by = \Auth::user()->creatorId();
                            }
                            $employeeAttendance->date = $request->date;
                            $employeeAttendance->status = 'Present';
                            $employeeAttendance->clock_in = $in;
                            $employeeAttendance->clock_out = $out;
                            $employeeAttendance->late = $late;
                            $employeeAttendance->early_leaving = $earlyLeaving;
                            $employeeAttendance->overtime = $overtime;
                            $employeeAttendance->total_rest = '00:00:00';
                            $employeeAttendance->save();

                        } else {
                            $attendance = AttendanceEmployee::where('employee_id', '=', $employee)->where('date', '=', $request->date)->first();

                            if (!empty($attendance)) {
                                $employeeAttendance = $attendance;
                            } else {
                                $employeeAttendance = new AttendanceEmployee();
                                $employeeAttendance->employee_id = $employee;
                                $employeeAttendance->created_by = \Auth::user()->creatorId();
                            }

                            $employeeAttendance->status = 'Absent';
                            $employeeAttendance->date = $request->date;
                            $employeeAttendance->clock_in = '00:00:00';
                            $employeeAttendance->clock_out = '00:00:00';
                            $employeeAttendance->late = '00:00:00';
                            $employeeAttendance->early_leaving = '00:00:00';
                            $employeeAttendance->overtime = '00:00:00';
                            $employeeAttendance->total_rest = '00:00:00';
                            $employeeAttendance->save();
                        }
                    }
                } else {
                    return redirect()->back()->with('error', __('Employee not found.'));
                }

                return redirect()->back()->with('success', __('Employee attendance successfully created.'));
            } else {
                return redirect()->back()->with('error', __('Branch & department field required.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    //for attendance employee report

    public function importFile()
    {
        return view('attendance.import');
    }

    public function attendanceImportdata(Request $request)
    {
        session_start();
        $html = '<h3 class="text-danger text-center">Below data is not inserted</h3></br>';
        $flag = 0;
        $html .= '<table class="table table-bordered"><tr>';
        try {
            $request = $request->data;
            $file_data = $_SESSION['file_data'];

            unset($_SESSION['file_data']);
        } catch (\Throwable $th) {
            $html = '<h3 class="text-danger text-center">Something went wrong, Please try again</h3></br>';
            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        }
        $user = Auth::user();

        foreach ($file_data as $key => $row) {
            $employeeData = Employee::Where('email', 'like', $row[$request['employee_email']])->where('created_by', \Auth::user()->creatorId())->first();

            if (!empty($employeeData)) {
                try {

                    $employeeId = $employeeData->id;

                    $clockIn = $row[$request['clock_in']];
                    $clockOut = $row[$request['clock_out']];

                    $inNorm = $this->normalizeAttendanceClock($clockIn);
                    $outNorm = $this->normalizeAttendanceClock($clockOut);
                    $hasTimes = $inNorm !== '00:00:00' || $outNorm !== '00:00:00';

                    if ($hasTimes) {
                        $status = 'Present';
                        $metrics = $this->resolveEmployeeAttendanceMetrics((int) $employeeId, $row[$request['date']], $inNorm, $outNorm);
                        $late = $metrics['late'];
                        $earlyLeaving = $metrics['early_leaving'];
                        $overtime = $metrics['overtime'];
                        $clockInSave = $inNorm;
                        $clockOutSave = $outNorm;
                    } else {
                        $status = 'Absent';
                        $late = '00:00:00';
                        $earlyLeaving = '00:00:00';
                        $overtime = '00:00:00';
                        $clockInSave = '00:00:00';
                        $clockOutSave = '00:00:00';
                    }

                    $check = AttendanceEmployee::where('employee_id', $employeeId)->where('date', $row[$request['date']])->first();
                    if ($check) {
                        $check->update([
                            'status' => $status,
                            'late' => $late,
                            'early_leaving' => $earlyLeaving,
                            'overtime' => $overtime,
                            'clock_in' => $clockInSave,
                            'clock_out' => $clockOutSave,
                            'total_rest' => '00:00:00',
                        ]);
                    } else {
                        AttendanceEmployee::create([
                            'employee_id' => $employeeId,
                            'date' => $row[$request['date']],
                            'status' => $status,
                            'late' => $late,
                            'early_leaving' => $earlyLeaving,
                            'overtime' => $overtime,
                            'clock_in' => $clockInSave,
                            'clock_out' => $clockOutSave,
                            'total_rest' => '00:00:00',
                            'created_by' => \Auth::user()->creatorId(),
                        ]);
                    }

                } catch (\Exception $e) {
                    $flag = 1;
                    $html .= '<tr>';

                    $html .= '<td>' . (isset($row[$request['employee_email']]) ? $row[$request['employee_email']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['date']]) ? $row[$request['date']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['clock_in']]) ? $row[$request['clock_in']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['clock_out']]) ? $row[$request['clock_out']] : '-') . '</td>';

                    $html .= '</tr>';
                }
            } else {
                $flag = 1;
                $html .= '<tr>';

                $html .= '<td>' . (isset($row[$request['employee_email']]) ? $row[$request['employee_email']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['date']]) ? $row[$request['date']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['clock_in']]) ? $row[$request['clock_in']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['clock_out']]) ? $row[$request['clock_out']] : '-') . '</td>';

                $html .= '</tr>';
            }
        }

        $html .= '
                        </table>
                        <br />
                        ';
        if ($flag == 1) {

            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        } else {
            return response()->json([
                'html' => false,
                'response' => 'Data Imported Successfully',
            ]);
        }

    }

    private function resolveEmployeeAttendanceMetrics(int $employeeId, string $dateYmd, string $clockIn, string $clockOut): array
    {
        $emp = Employee::find($employeeId);
        $window = EmployeeAttendanceScheduleService::resolveWindow($emp, (int) (\Auth::user()->creatorId() ?? 0));

        return EmployeeAttendanceScheduleService::computeMetrics($dateYmd, $clockIn, $clockOut, $window);
    }

    private function normalizeAttendanceClock(?string $clock): string
    {
        if ($clock === null || $clock === '') {
            return '00:00:00';
        }
        $clock = trim((string) $clock);
        if (substr_count($clock, ':') === 2) {
            return EmployeeAttendanceScheduleService::normalizeTimeString($clock);
        }
        if (substr_count($clock, ':') === 1) {
            return EmployeeAttendanceScheduleService::normalizeTimeString($clock . ':00');
        }

        return EmployeeAttendanceScheduleService::normalizeTimeString($clock);
    }

    private function formatClockForStorage(?string $clock): string
    {
        $normalized = $this->normalizeAttendanceClock($clock);

        return strlen($normalized) === 5 ? $normalized.':00' : $normalized;
    }

    private function applyAbsentAttendance(AttendanceEmployee $attendance): void
    {
        $attendance->status = 'Absent';
        $attendance->clock_in = '00:00:00';
        $attendance->clock_out = '00:00:00';
        $attendance->late = '00:00:00';
        $attendance->early_leaving = '00:00:00';
        $attendance->overtime = '00:00:00';
        $attendance->total_rest = '00:00:00';
    }
}
