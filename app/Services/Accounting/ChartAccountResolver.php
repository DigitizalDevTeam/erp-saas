<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;

class ChartAccountResolver
{
    /**
     * Resolve chart account id by exact code or by prefix (first match by code order).
     */
    public function resolveByCodeOrPrefix(int $createdBy, string $codeOrPrefix): ?int
    {
        if ($codeOrPrefix === '' || $codeOrPrefix === '0') {
            return null;
        }

        if (ctype_digit($codeOrPrefix)) {
            $exact = ChartOfAccount::where('created_by', $createdBy)
                ->where('code', (int) $codeOrPrefix)
                ->first();
            if ($exact) {
                return (int) $exact->id;
            }
        }

        return ChartOfAccount::where('created_by', $createdBy)
            ->whereRaw('CAST(code AS CHAR) LIKE ?', [$codeOrPrefix.'%'])
            ->orderByRaw('LENGTH(CAST(code AS CHAR)) ASC')
            ->orderBy('code')
            ->value('id');
    }

    /**
     * First two digits of numeric account code (SNC-style classes 61–88).
     */
    public function twoDigitClass(int|string $code): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $code);
        if (strlen($digits) < 2) {
            return null;
        }

        return (int) substr($digits, 0, 2);
    }
}
