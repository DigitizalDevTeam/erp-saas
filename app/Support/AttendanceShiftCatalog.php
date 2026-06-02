<?php

namespace App\Support;

/**
 * Simple preset shifts for hotel / shift-based attendance and POS operational shifts.
 */
class AttendanceShiftCatalog
{
    public const OFF = 'off';

    public const MORNING = 'morning';

    public const AFTERNOON = 'afternoon';

    public const NIGHT = 'night';

    public const CUSTOM = 'custom';

    public static function operationalShiftOptions(): array
    {
        return [
            self::MORNING => __('Morning'),
            self::AFTERNOON => __('Afternoon'),
            self::NIGHT => __('Night'),
        ];
    }

    public static function rosterOptions(): array
    {
        return [
            self::OFF => __('Off'),
            self::MORNING => __('Morning'),
            self::AFTERNOON => __('Afternoon'),
            self::NIGHT => __('Night'),
            self::CUSTOM => __('Custom times'),
        ];
    }

    /**
     * @return array{start: string, end: string, crosses_midnight: bool}|null
     */
    public static function presetWindow(string $code): ?array
    {
        return match ($code) {
            self::MORNING => ['start' => '07:00:00', 'end' => '15:00:00', 'crosses_midnight' => false],
            self::AFTERNOON => ['start' => '15:00:00', 'end' => '23:00:00', 'crosses_midnight' => false],
            self::NIGHT => ['start' => '23:00:00', 'end' => '07:00:00', 'crosses_midnight' => true],
            self::OFF => null,
            default => null,
        };
    }
}
