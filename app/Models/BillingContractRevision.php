<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 1 — billing contract revision.
 *
 * An amendment is a new revision: author (created_by), reason,
 * effective date and a JSON snapshot of the lines + schedule +
 * terms at that revision. Issued invoices keep their old
 * revision; a new revision applies to unissued periods from the
 * effective date only.
 */
class BillingContractRevision extends Model
{
    protected $fillable = [
        'contract_id', 'revision_number', 'effective_date', 'reason',
        'snapshot', 'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'snapshot' => 'array',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }
}
