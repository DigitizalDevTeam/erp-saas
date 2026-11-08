<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 0 — asset numbering (plan §5, spec §10, §24).
 *
 * Pattern `{PREFIX}-{CATEGORY}-{YEAR}-{SEQUENCE}`, e.g.
 * `HOYA-IT-2026-0001`. The sequence is per company + category +
 * year and a number is NEVER reused: the allocation scans the
 * existing codes under a row lock (same pattern as
 * TreasuryReceiptService::nextReference) and the code column
 * carries a unique index per company, so a concurrent duplicate
 * throws instead of silently reusing a number.
 *
 * Numbers are allocated only at capitalization (Phase 1) — drafts
 * hold no number. This service is the allocation primitive; it
 * MUST be called inside a DB transaction by the caller, otherwise
 * the lockForUpdate() has nothing to hold.
 */
class FixedAssetNumberingService
{
    /**
     * Next free code for a company + category code + year.
     * $categoryCode is the category's short code (e.g. IT, FUR,
     * VEH) — uppercased and stripped of anything but A-Z0-9.
     */
    public function nextCode(int $creatorId, string $categoryCode, ?string $year = null): string
    {
        $year = $year ?: date('Y');
        $category = $this->normalizeCode($categoryCode);
        $head = $this->companyPrefix($creatorId).'-'.$category.'-'.$year.'-';

        $max = 0;
        // withTrashed(): a soft-deleted asset's code stays burned —
        // the scan must see it, or the number would be reused.
        $existing = FixedAsset::withTrashed()
            ->where('created_by', $creatorId)
            ->where('code', 'like', $head.'%')
            ->lockForUpdate()
            ->pluck('code');

        foreach ($existing as $code) {
            $suffix = substr((string) $code, strlen($head));
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $head.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Company code for asset numbers: configured prefix, else the company name. */
    public function companyPrefix(int $creatorId): string
    {
        $configured = strtoupper(trim((string) (FixedAssetSettings::get($creatorId, FixedAssetSettings::KEY_REFERENCE_PREFIX) ?? '')));

        if ($configured !== '') {
            return preg_replace('/[^A-Z0-9]/', '', $configured) ?: 'CO';
        }

        $user = User::find($creatorId);
        $letters = $user ? preg_replace('/[^A-Za-z]/', '', (string) $user->name) : '';

        return $letters !== '' ? strtoupper(substr($letters, 0, 4)) : 'CO';
    }

    public function normalizeCode(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return $clean !== '' ? $clean : 'GEN';
    }

    /**
     * Allocate the next code inside a transaction and reserve it
     * by inserting the asset row in the same transaction. The
     * caller supplies the asset attributes (without code); the
     * code is allocated, set and the row is created atomically.
     */
    public function allocateAndCreate(int $creatorId, string $categoryCode, array $attributes): FixedAsset
    {
        return DB::transaction(function () use ($creatorId, $categoryCode, $attributes) {
            $year = isset($attributes['capitalization_date'])
                ? (string) date('Y', strtotime((string) $attributes['capitalization_date']))
                : date('Y');

            $attributes['code'] = $this->nextCode($creatorId, $categoryCode, $year);
            $attributes['created_by'] = $creatorId;
            $attributes['company_id'] = $creatorId;

            return FixedAsset::create($attributes);
        });
    }
}
