<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostCentre extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'parent_id',
        'code',
        'name',
        'description',
        'manager_user_id',
        'status',
        'effective_from',
        'effective_to',
        'updated_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Is the centre inside its effective window on the given date?
     * A null effective_from means "from the beginning"; a null
     * effective_to means "no end".
     */
    public function isEffectiveOn(?Carbon $date = null): bool
    {
        $date = ($date ?? Carbon::today())->startOfDay();

        if ($this->effective_from && $this->effective_from->copy()->startOfDay()->greaterThan($date)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->copy()->startOfDay()->lessThan($date)) {
            return false;
        }

        return true;
    }

    /**
     * May this centre take a NEW transaction (posting) on the given date?
     * Inactive centres stay reportable for history but take nothing new.
     * Parents are summary-only (plan D2): a centre with children never
     * takes a direct posting — only its leaf children do.
     */
    public function canAcceptTransactions(?Carbon $date = null, bool $parentsSummaryOnly = true): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (! $this->isEffectiveOn($date)) {
            return false;
        }

        if ($parentsSummaryOnly && $this->hasChildren()) {
            return false;
        }

        return true;
    }
}
