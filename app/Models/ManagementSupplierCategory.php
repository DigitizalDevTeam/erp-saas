<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier -> default category. When a document is created for a
 * supplier that has a default here, the category is auto-assigned —
 * the user can still change it on that document. Matching is by the
 * supplier NAME (case-insensitive, trimmed); the NIF is stored for
 * reference and to tell two same-named suppliers apart.
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementSupplierCategory extends Model
{
    protected $fillable = [
        'created_by',
        'supplier_name',
        'supplier_nif',
        'management_rule_id',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ManagementRule::class, 'management_rule_id');
    }

    /**
     * Find the default rule for a supplier name, if one was saved.
     */
    public static function defaultRuleFor(int $creatorId, ?string $supplierName): ?ManagementRule
    {
        if ($supplierName === null || trim($supplierName) === '') {
            return null;
        }

        $row = static::where('created_by', $creatorId)
            ->whereRaw('LOWER(TRIM(supplier_name)) = ?', [mb_strtolower(trim($supplierName))])
            ->with('rule')
            ->first();

        return $row?->rule;
    }
}
