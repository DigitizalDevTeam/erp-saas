<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the company's cost centres (the file's "101 | Restaurante",
 * "102 | Group"). A document may have NO cost centre — those costs are
 * shown on their own row in the Costs tabs. Each company defines its
 * OWN centres (a hotel has hotel / restaurant / events / office; a
 * small company may have none at all).
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementCostCentre extends Model
{
    protected $fillable = [
        'created_by',
        'code',
        'name',
    ];

    /** The file's two example centres, seeded when a company has none. */
    public const DEFAULT_CENTRES = [
        ['code' => '101', 'name' => 'Restaurante'],
        ['code' => '102', 'name' => 'Group'],
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(ManagementDocument::class);
    }

    /** Display label, as in the file: "101 | Restaurante". */
    public function label(): string
    {
        return "{$this->code} | {$this->name}";
    }

    /**
     * Seed the file's two centres for a company that has none yet.
     * A company is never forced to use them — it can delete or rename
     * them, or run with no centres at all.
     */
    public static function ensureSeeded(int $creatorId): void
    {
        if (static::where('created_by', $creatorId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_CENTRES as $row) {
            static::create(array_merge($row, ['created_by' => $creatorId]));
        }
    }
}
