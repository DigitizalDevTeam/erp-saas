<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    /**
     * Credit Notes Phase 2 — approval status, mirroring JournalEntry
     * (0 pending / 1 approved / 2 rejected). Notes issued while the
     * approval switch is off are created approved; while it is on
     * they are created pending and need an 'approve credit note'
     * approval before any refund can be paid.
     */
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'invoice',
        'customer',
        'amount',
        'date',
    ];

    // public function customer()
    // {
    //     return $this->hasOne('App\Models\Customer', 'customer_id', 'customer');
    // }
    
    public function customerRelation()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'customer');
    }
    public function invoice_data()
    {
        return $this->hasOne('App\Models\Invoice', 'id', 'invoice');
    }
}
