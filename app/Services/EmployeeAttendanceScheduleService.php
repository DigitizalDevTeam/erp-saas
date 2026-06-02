<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Utility;
use Carbon\Carbon;

/**
 * Resolves per-employee attendance windows (vs company defaults) and computes late / early leave / overtime.
 */
class EmployeeAttendanceScheduleService
{
    public const TYPE_OFFICE = 'office_fixed';

    public const TYPE_SHIFT = 'shift';

    public const TYPE_FLEXIBLE = 'flexible_site';

    /**
     * @return array{type: string, start: string, end: string, grace_minutes: int, flexible: bool, crosses_midnight?: bool}
     */
    public static function resolveWindow(?Employee $employee, ?int $creatorId = null, ?string $dateYmd = null): array
    {
        $creatorId = $creatorId ?? (int) (auth()->user()->creatorId() ?? 0);
        $settings = $creatorId ? Utility::settingsById($creatorId) : Utility::settings();

        $companyStart = self::normalizeTimeString($settings['company_start_time'] ?? '09:00');
        $companyEnd = self::normalizeTimeString($settings['company_end_time'] ?? '18:00');

        if (! $employee) {
            return [
                'type' => self::TYPE_OFFICE,
                'start' => $companyStart,
                'end' => $companyEnd,
                'grace_minutes' => 0,
                'flexible' => false,
            ];
        }

        $type = $employee->work_schedule_type ?: self::TYPE_OFFICE;
        if (! in_array($type, [self::TYPE_OFFICE, self::TYPE_SHIFT, self::TYPE_FLEXIBLE], true)) {
            $type = self::TYPE_OFFICE;
        }

        $grace = max(0, min(24 * 60, (int) ($employee->grace_minutes ?? 0)));

        if ($type === self::TYPE_FLEXIBLE) {
            return [
                'type' => $type,
                'start' => $companyStart,
                'end' => $companyEnd,
                'grace_minutes' => $grace,
                'flexible' => true,
                'crosses_midnight' => false,
            ];
        }

        $dateYmd = $dateYmd ?: date('Y-m-d');
        if ($type === self::TYPE_SHIFT) {
            $roster = EmployeeWeeklyShiftService::windowForDate($employee, $dateYmd);
            if ($roster) {
                return [
                    'type' => $type,
                    'start' => self::normalizeTimeString($roster['start']),
                    'end' => self::normalizeTimeString($roster['end']),
                    'grace_minutes' => $grace,
                    'flexible' => false,
                    'crosses_midnight' => ! empty($roster['crosses_midnight']),
                ];
            }

            return [
                'type' => $type,
                'start' => $companyStart,
                'end' => $companyEnd,
                'grace_minutes' => $grace,
                'flexible' => true,
                'crosses_midnight' => false,
            ];
        }

        $useCompany = (bool) ($employee->attendance_use_company_default ?? true);
        $ws = self::timeFromModel($employee->work_start_time);
        $we = self::timeFromModel($employee->work_end_time);

        if ($useCompany || $ws === null || $we === null) {
            return [
                'type' => $type,
                'start' => $companyStart,
                'end' => $companyEnd,
                'grace_minutes' => $grace,
                'flexible' => false,
                'crosses_midnight' => self::timesCrossMidnight($ws, $we),
            ];
        }

        $start = self::normalizeTimeString($ws);
        $end = self::normalizeTimeString($we);

        return [
            'type' => $type,
            'start' => $start,
            'end' => $end,
            'grace_minutes' => $grace,
            'flexible' => false,
            'crosses_midnight' => self::timesCrossMidnight($start, $end),
        ];
    }

    private static function timesCrossMidnight(string $start, string $end): bool
    {
        return strtotime($start) >= strtotime($end);
    }

    /**
     * Display times for the employee HRM dashboard — same window as clock-in / attendance rules.
     *
     * @return array{startTime: string, endTime: string, flexible: bool, grace_minutes: int}
     */
    public static function officeTimeForDashboard(?Employee $employee, ?int $creatorId = null): array
    {
        $window = self::resolveWindow($employee, $creatorId);

        return [
            'startTime' => Carbon::parse($window['start'])->format('H:i'),
            'endTime' => Carbon::parse($window['end'])->format('H:i'),
            'flexible' => ! empty($window['flexible']),
            'grace_minutes' => (int) ($window['grace_minutes'] ?? 0),
        ];
    }

    /**
     * @return array{late: string, early_leaving: string, overtime: string}
     */
    /**
     * Late at clock-in only (before clock-out is known).
     */
    public static function computeLateAtClockIn(string $dateYmd, string $clockIn, array $window): string
    {
        if (! empty($window['flexible'])) {
            return '00:00:00';
        }
        $in = self::normalizeTimeString($clockIn);
        $expectedStartTs = strtotime($dateYmd . ' ' . $window['start']) + (int) ($window['grace_minutes'] ?? 0) * 60;
        $clockInTs = strtotime($dateYmd . ' ' . $in);

        return self::secondsToTime(max(0, $clockInTs - $expectedStartTs));
    }

    public static function computeMetrics(string $dateYmd, string $clockIn, string $clockOut, array $window): array
    {
        if (! empty($window['flexible'])) {
            return [
                'late' => '00:00:00',
                'early_leaving' => '00:00:00',
                'overtime' => '00:00:00',
            ];
        }

        $in = self::normalizeTimeString($clockIn);
        $out = self::normalizeTimeString($clockOut);
        $grace = (int) ($window['grace_minutes'] ?? 0);

        $expectedStartTs = strtotime($dateYmd . ' ' . $window['start']) + $grace * 60;
        $clockInTs = strtotime($dateYmd . ' ' . $in);
        $lateSeconds = max(0, $clockInTs - $expectedStartTs);

        $crosses = ! empty($window['crosses_midnight']);
        $endTs = strtotime($dateYmd . ' ' . $window['end']);
        if ($crosses) {
            $endTs = strtotime($dateYmd . ' ' . $window['end'] . ' +1 day');
        }
        $outTs = strtotime($dateYmd . ' ' . $out);
        if ($crosses && strtotime($out) <= strtotime($window['start'])) {
            $outTs = strtotime($dateYmd . ' ' . $out . ' +1 day');
        }

        $earlySeconds = max(0, $endTs - $outTs);
        $otSeconds = max(0, $outTs - $endTs);

        return [
            'late' => self::secondsToTime($lateSeconds),
            'early_leaving' => self::secondsToTime($earlySeconds),
            'overtime' => self::secondsToTime($otSeconds),
        ];
    }

    public static function secondsToTime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor($seconds / 60 % 60);
        $s = (int) floor($seconds % 60);

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    public static function normalizeTimeString(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '09:00:00';
        }
        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (\Throwable $e) {
            return '09:00:00';
        }
    }

    private static function timeFromModel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

}
