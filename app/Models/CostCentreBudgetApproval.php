<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreBudgetApproval extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_MANAGER_APPROVED = 'manager_approved';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_MANAGER_APPROVED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'company_id',
        'created_by',
        'cost_centre_id',
        'budget_id',
        'commitment_id',
        'fiscal_year',
        'period',
        'gl_account_id',
        'project_id',
        'scope_key',
        'amount',
        'currency_code',
        'reason',
        'status',
        'requested_by',
        'manager_user_id',
        'controller_user_id',
        'requires_manager',
        'requires_controller',
        'manager_decided_by',
        'manager_decided_at',
        'manager_decision',
        'manager_note',
        'controller_decided_by',
        'controller_decided_at',
        'controller_decision',
        'controller_note',
        'auto_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requires_manager' => 'boolean',
        'requires_controller' => 'boolean',
        'manager_decided_at' => 'datetime',
        'controller_decided_at' => 'datetime',
    ];

    public function centre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function budget()
    {
        return $this->belongsTo(CostCentreBudget::class, 'budget_id');
    }

    public function commitment()
    {
        return $this->belongsTo(CostCentreCommitment::class, 'commitment_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    /**
     * Which step is waiting for a decision: 'manager', 'controller',
     * or null when the request is already decided.
     */
    public function currentStep(): ?string
    {
        if ($this->status === self::STATUS_PENDING && $this->requires_manager) {
            return 'manager';
        }

        if ($this->status === self::STATUS_PENDING && ! $this->requires_manager && $this->requires_controller) {
            return 'controller';
        }

        if ($this->status === self::STATUS_MANAGER_APPROVED && $this->requires_controller) {
            return 'controller';
        }

        return null;
    }
}
