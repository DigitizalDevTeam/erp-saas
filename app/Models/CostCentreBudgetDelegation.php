<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CostCentreBudgetDelegation extends Model
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CONTROLLER = 'controller';

    public const ROLES = [
        self::ROLE_MANAGER,
        self::ROLE_CONTROLLER,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'delegator_user_id',
        'delegate_user_id',
        'role',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isActiveOn(?Carbon $date = null): bool
    {
        $date = ($date ?? Carbon::today())->startOfDay();

        if ($this->valid_from && $this->valid_from->copy()->startOfDay()->greaterThan($date)) {
            return false;
        }

        if ($this->valid_to && $this->valid_to->copy()->startOfDay()->lessThan($date)) {
            return false;
        }

        return true;
    }
}
