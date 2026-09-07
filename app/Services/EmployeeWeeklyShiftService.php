<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeWeeklyShift;
use App\Support\AttendanceShiftCatalog;
use Carbon\Carbon;

class EmployeeWeeklyShiftService
{
    public static function syncFromRequest(Employee $employee, array $weeklyInput, int $creatorId): void
    {
        EmployeeWeeklyShift::where('employee_id', $employee->id)->delete();

        if (($employee->work_schedule_type ?? '') !== EmployeeAttendanceScheduleService::TYPE_SHIFT) {
            return;
        }

        for ($day = 1; $day <= 7; $day++) {
            $row = $weeklyInput[$day] ?? $weeklyInput[(string) $day] ?? [];
            if (! is_array($row)) {
                continue;
            }
            $code = $row['shift_code'] ?? AttendanceShiftCatalog::OFF;
            if (! in_array($code, array_keys(AttendanceShiftCatalog::rosterOptions()), true)) {
                $code = AttendanceShiftCatalog::OFF;
            }

            $start = null;
            $end = null;
            $crosses = false;

            if ($code === AttendanceShiftCatalog::CUSTOM) {
                $start = self::normalizeTime($row['start_time'] ?? null);
                $end = self::normalizeTime($row['end_time'] ?? null);
                $crosses = ! empty($row['crosses_midnight']) || ($start && $end && $start > $end);
            } elseif ($code !== AttendanceShiftCatalog::OFF) {
                $preset = AttendanceShiftCatalog::presetWindow($code);
                if ($preset) {
                    $start = $preset['start'];
                    $end = $preset['end'];
                    $crosses = $preset['crosses_midnight'];
                }
            }

            if ($code === AttendanceShiftCatalog::OFF) {
                continue;
            }

            EmployeeWeeklyShift::create([
                'employee_id' => $employee->id,
                'day_of_week' => $day,
                'shift_code' => $code,
                'start_time' => $start,
                'end_time' => $end,
                'crosses_midnight' => $crosses,
                'created_by' => $creatorId,
            ]);
        }
    }

    /**
     * @return array<int, EmployeeWeeklyShift>
     */
    public static function rosterByDay(int $employeeId): array
    {
        $rows = EmployeeWeeklyShift::where('employee_id', $employeeId)->get()->keyBy('day_of_week');

        return $rows->all();
    }

    /**
     * @return array{start: string, end: string, crosses_midnight: bool, shift_code: string}|null
     */
    public static function windowForDate(Employee $employee, string $dateYmd): ?array
    {
        $day = Carbon::parse($dateYmd)->dayOfWeekIso;
        $row = EmployeeWeeklyShift::where('employee_id', $employee->id)
            ->where('day_of_week', $day)
            ->first();

        if (! $row) {
            return null;
        }

        if ($row->shift_code === AttendanceShiftCatalog::OFF) {
            return null;
        }

        if ($row->shift_code === AttendanceShiftCatalog::CUSTOM && $row->start_time && $row->end_time) {
            return [
                'shift_code' => $row->shift_code,
                'start' => Carbon::parse($row->start_time)->format('H:i:s'),
                'end' => Carbon::parse($row->end_time)->format('H:i:s'),
                'crosses_midnight' => (bool) $row->crosses_midnight,
            ];
        }

        $preset = AttendanceShiftCatalog::presetWindow($row->shift_code);

        return $preset ? array_merge($preset, ['shift_code' => $row->shift_code]) : null;
    }

    private static function normalizeTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
