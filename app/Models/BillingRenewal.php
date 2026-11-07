<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 3 — billing renewal event.
 *
 * One row per renewal: an old contract renewed into a new
 * contract (the renewal draft). Types: manual (an operator
 * clicked Renew) or automatic (the contract's auto_renew flag
 * — recorded the same way when it fires). Statuses: pending
 * (the new contract is still a draft), renewed (the new
 * contract became active), not_renewed (the renewal draft was
 * abandoned and the old contract simply ends).
 */
class BillingRenewal extends Model
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTOMATIC = 'automatic';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RENEWED = 'renewed';
    public const STATUS_NOT_RENEWED = 'not_renewed';

    protected $fillable = [
        'created_by', 'contract_id', 'new_contract_id',
        'effective_date', 'type', 'status', 'notice_sent_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'notice_sent_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }

    public function newContract()
    {
        return $this->belongsTo(BillingContract::class, 'new_contract_id');
    }
}
