<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 1 — billing contract audit event.
 *
 * Append-only. Every lifecycle transition, approval, amendment,
 * suspension, resume, end and cancel writes one row with actor,
 * action, reason and before/after values. Nothing here is ever
 * edited or deleted.
 */
class BillingContractAuditEvent extends Model
{
    protected $fillable = [
        'contract_id', 'occurrence_id', 'actor_id', 'action', 'reason',
        'before_values', 'after_values', 'created_by',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }
}
