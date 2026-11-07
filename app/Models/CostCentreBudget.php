<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreBudget extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'fiscal_year',
        'period',
        'cost_centre_id',
        'gl_account_id',
        'project_id',
        'scope_key',
        'budget_amount',
        'approved_amount',
        'currency_code',
        'status',
        'approved_by',
        'approved_at',
        'updated_by',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function centre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * The scope key for a (GL account, project) pair. 'all' means the
     * whole centre; anything more specific narrows the budget. The
     * key exists because MySQL treats NULLs in a unique index as
     * distinct values, so the columns alone cannot enforce "one budget
     * per scope".
     */
    public static function scopeKey(?int $glAccountId, ?int $projectId): string
    {
        $parts = [];

        if ($glAccountId !== null) {
            $parts[] = 'gl:'.$glAccountId;
        }

        if ($projectId !== null) {
            $parts[] = 'prj:'.$projectId;
        }

        return $parts === [] ? 'all' : implode(':', $parts);
    }

    /**
     * How specific this budget is: account + project (3) beats
     * account-only (2), which beats project-only (1), which beats the
     * whole centre (0). A budget check uses the most specific matching
     * budget.
     */
    public function specificity(): int
    {
        return ($this->gl_account_id !== null ? 2 : 0) + ($this->project_id !== null ? 1 : 0);
    }
}
