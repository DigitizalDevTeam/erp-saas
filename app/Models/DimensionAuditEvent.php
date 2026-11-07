<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for dimensions (plan §5). Rows are written,
 * never updated or deleted — the history of an allocation (created,
 * posted, reversed, approved, rebased) is the audit.
 */
class DimensionAuditEvent extends Model
{
    protected $fillable = [
        'company_id',
        'entity_type',
        'entity_id',
        'action',
        'before_json',
        'after_json',
        'actor_id',
        'occurred_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public static function record(
        int $companyId,
        string $entityType,
        int $entityId,
        string $action,
        ?array $before,
        ?array $after,
        ?int $actorId
    ): self {
        return self::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before,
            'after_json' => $after,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }
}
