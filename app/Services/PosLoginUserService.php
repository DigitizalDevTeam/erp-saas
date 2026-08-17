<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosLoginUserService
{
    private const CACHE_KEY = 'pos_login_users_v1';

    private const CACHE_TTL_MINUTES = 5;

    /**
     * @var array<int, string>
     */
    private const POS_PERMISSIONS = [
        'manage pos',
        PosRestaurantRoleService::PERMISSION_CASHIER,
        PosRestaurantRoleService::PERMISSION_WAITER,
        PosRestaurantRoleService::PERMISSION_MANAGER,
    ];

    /**
     * @return array<int, array{id: int, email: string, display_label: string}>
     */
    public function eligibleUsersForLoginDropdown(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildEligibleUsersList()
        );
    }

    public static function displayLabel(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : (string) $user->email;
    }

    public function isEligibleForPosLogin(User $user): bool
    {
        if (CompanyUserRoleService::isPlatformSuperAdmin($user)) {
            return false;
        }

        if ($user->pos_pin === null || $user->pos_pin === '') {
            return false;
        }

        if (! $user->canManagePos()) {
            return false;
        }

        if ((int) $user->is_active !== 1 || (int) $user->delete_status !== 1) {
            return false;
        }

        if ($user->is_disable == 0 && $user->type != 'company' && $user->type != 'super admin') {
            return false;
        }

        $activeMembershipCount = $this->activeCompanyMembershipCount($user);
        $companyId = $this->resolveCompanyId($user);
        $companyUser = $companyId ? User::find($companyId) : null;

        $isUserLoginDisabled = $user->type != 'super admin'
            && $user->type != 'company'
            && $activeMembershipCount <= 1
            && (int) $user->is_enable_login === 0;

        $isCompanyLoginDisabled = $user->type != 'super admin'
            && $companyUser != null
            && (int) $companyUser->is_enable_login === 0;

        if ($isUserLoginDisabled || $isCompanyLoginDisabled) {
            return false;
        }

        if ($companyUser && $companyUser->plan) {
            $plan = Plan::find($companyUser->plan);
            if ($plan && (int) $plan->pos !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{id: int, email: string, display_label: string}>
     */
    private function buildEligibleUsersList(): array
    {
        $users = User::query()
            ->whereNotNull('pos_pin')
            ->where('pos_pin', '!=', '')
            ->where('is_active', 1)
            ->where('delete_status', 1)
            ->where('type', '!=', 'super admin')
            ->permission(self::POS_PERMISSIONS)
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        return $users
            ->filter(fn (User $user) => $this->isEligibleForPosLogin($user))
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'display_label' => self::displayLabel($user),
            ])
            ->values()
            ->all();
    }

    private function resolveCompanyId(User $user): ?int
    {
        if (CompanyUserRoleService::isPlatformSuperAdmin($user)) {
            return null;
        }

        if (! empty($user->default_company_id) && $user->canAccessCompany((int) $user->default_company_id)) {
            return (int) $user->default_company_id;
        }

        $first = $user->firstActiveCompanyId();
        if ($first) {
            return (int) $first;
        }

        return ! empty($user->created_by) ? (int) $user->created_by : null;
    }

    private function activeCompanyMembershipCount(User $user): int
    {
        if (! Schema::hasTable('company_user')) {
            return 0;
        }

        return (int) DB::table('company_user')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->count();
    }
}
