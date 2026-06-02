<?php

namespace App\Services;

use App\Models\PosDayClosed;

class BusinessDayLockService
{
    public static function isLocked(int $creatorId, string $dateYmd): bool
    {
        return PosDayClosed::where('created_by', $creatorId)
            ->where('closed_date', $dateYmd)
            ->exists();
    }

    public static function assertNotLocked(int $creatorId, string $dateYmd): ?string
    {
        if (self::isLocked($creatorId, $dateYmd)) {
            return __('This business day is closed (night audit). No further changes allowed.');
        }

        return null;
    }
}
