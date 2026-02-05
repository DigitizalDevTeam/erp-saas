<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'price',
        'duration',
        'max_users',
        'max_customers',
        'max_venders',
        'max_clients',
        'trial',
        'trial_days',
        'description',
        'image',
        'crm',
        'hrm',
        'account',
        'project',
        'pos',
        'stock',
        'max_pos_users',
        'chatgpt',
        'storage_limit',
    ];

    private static $getplans = NULL;

    public static $arrDuration = [
        'lifetime' => 'Lifetime',
        'month' => 'Per Month',
        'year' => 'Per Year',
    ];

    public function status()
    {
        return [
            __('lifetime'),
            __('Per Month'),
            __('Per Year'),
        ];
    }

    public static function total_plan()
    {
        return Plan::count();
    }

    public static function most_purchese_plan()
    {
        $free_plan = Plan::where('price', '<=', 0)->first()->id;
        $plan =  User::select(DB::raw('count(*) as total') , 'plan')->where('type', '=', 'company')->where('plan', '!=', $free_plan)->groupBy('plan')->first();

        return $plan;
    }

    public static function getPlan($id)
    {
        if(self::$getplans == null)
        {
            $plan = Plan::find($id);
            self::$getplans = $plan;
        }

        return self::$getplans;
    }

    /**
     * Phase 5: Count users who can use POS for a company (creatorId = company owner id).
     */
    public static function countPosUsersForCompany(int $creatorId): int
    {
        $userIds = User::where('created_by', $creatorId)->orWhere('id', $creatorId)->pluck('id');
        $count = 0;
        foreach ($userIds as $id) {
            $u = User::find($id);
            if ($u && $u->can('manage pos')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Phase 5: Check if company can add another POS user (under plan limit).
     */
    public static function canCompanyAddPosUser(int $creatorId, ?int $excludeUserId = null): bool
    {
        $owner = User::find($creatorId);
        if (!$owner || !$owner->plan) {
            return true;
        }
        $plan = Plan::find($owner->plan);
        if (!$plan || $plan->pos != 1 || $plan->max_pos_users < 0) {
            return true;
        }
        $count = self::countPosUsersForCompany($creatorId);
        if ($excludeUserId && $count > 0) {
            $exclude = User::find($excludeUserId);
            if ($exclude && $exclude->can('manage pos') && $exclude->creatorId() == $creatorId) {
                $count--;
            }
        }
        return $count < $plan->max_pos_users;
    }
}
