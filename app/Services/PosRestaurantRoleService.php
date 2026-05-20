<?php

namespace App\Services;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates and maintains POS Restaurant roles (Cashier, Waiter, Manager) and permissions
 * for a company. Call when a new company is registered/created and run for existing
 * companies via migration or artisan command.
 */
class PosRestaurantRoleService
{
    public const GUARD = 'web';

    /** Permission: full POS restaurant (tables, orders, payments, cash-up, reports) */
    public const PERMISSION_CASHIER = 'pos restaurant full';

    /** Permission: only assigned tables, take orders, send to kitchen/bar */
    public const PERMISSION_WAITER = 'pos restaurant waiter';

    /** Permission: view all sales, shifts, reports, EOD */
    public const PERMISSION_MANAGER = 'pos restaurant manager';

    /** Permission: POS Production only (Kitchen/Bar – view orders, update line status) */
    public const PERMISSION_POS_PRODUCTION = 'manage pos production';

    public const ROLE_CASHIER = 'Cashier';
    public const ROLE_WAITER = 'Waiter';
    public const ROLE_MANAGER = 'Manager';
    public const ROLE_BAR = 'Bar';
    public const ROLE_KITCHEN = 'Kitchen';


    /** All permissions that must exist globally */
    public static function permissionNames(): array
    {
        return [
            self::PERMISSION_CASHIER,
            self::PERMISSION_WAITER,
            self::PERMISSION_MANAGER,
            self::PERMISSION_POS_PRODUCTION,
        ];
    }

    /**
     * Ensure global permissions exist (idempotent). Call from migration or app bootstrap.
     */
    public static function ensurePermissionsExist(): void
    {
        foreach (self::permissionNames() as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['name' => $name, 'guard_name' => self::GUARD]
            );
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create POS Restaurant roles for a company and assign permissions. Idempotent:
     * if roles already exist for this company, they are left unchanged (permissions synced).
     *
     * @param int $companyId User id of the company owner (type = company). Roles will have created_by = $companyId.
     */
    public static function createPosRestaurantRolesForCompany(int $companyId): void
    {
        self::ensurePermissionsExist();

        $roleConfig = [
            self::ROLE_CASHIER => [self::PERMISSION_CASHIER, 'show pos dashboard'],
            self::ROLE_WAITER  => [self::PERMISSION_WAITER, 'show pos dashboard'],
            self::ROLE_MANAGER => [self::PERMISSION_MANAGER, 'show pos dashboard'],
            self::ROLE_BAR     => [self::PERMISSION_POS_PRODUCTION, 'show pos dashboard'],
            self::ROLE_KITCHEN => [self::PERMISSION_POS_PRODUCTION, 'show pos dashboard'],
        ];

        foreach ($roleConfig as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)
                ->where('guard_name', self::GUARD)
                ->where('created_by', $companyId)
                ->first();

            if (!$role) {
                $role = Role::query()->create([
                    'name'       => $roleName,
                    'guard_name' => self::GUARD,
                    'created_by' => $companyId,
                ]);
            }

            $permissions = collect($permissionNames)->map(function ($name) {
                $p = Permission::where('name', $name)->where('guard_name', self::GUARD)->first();
                if (!$p) {
                    throw new \RuntimeException("Permission [{$name}] for guard [".self::GUARD.'] not found. Run ensurePermissionsExist() first.');
                }
                return $p;
            });
            $role->syncPermissions($permissions);
        }
    }

    /**
     * Run createPosRestaurantRolesForCompany for all existing companies (users with type = company).
     * Used by migration or artisan command for existing companies.
     */
    public static function createForAllExistingCompanies(): int
    {
        self::ensurePermissionsExist();

        $companyIds = User::where('type', 'company')->pluck('id');
        $count = 0;
        foreach ($companyIds as $id) {
            self::createPosRestaurantRolesForCompany((int) $id);
            $count++;
        }
        return $count;
    }

    /** @return string[] */
    public static function posBranchRoleNames(): array
    {
        return [
            self::ROLE_CASHIER,
            self::ROLE_WAITER,
            self::ROLE_MANAGER,
            self::ROLE_BAR,
            self::ROLE_KITCHEN,
        ];
    }

    public static function roleRequiresPosBranch(\Spatie\Permission\Models\Role $role): bool
    {
        if (in_array($role->name, self::posBranchRoleNames(), true)) {
            return true;
        }

        foreach (self::permissionNames() as $permission) {
            if ($role->hasPermissionTo($permission, self::GUARD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Role ids for user create/edit JS (show POS branch + PIN fields).
     *
     * @return string[]
     */
    public static function roleIdsRequiringPosBranch(int $companyId): array
    {
        return Role::where('created_by', $companyId)
            ->where('name', '!=', 'client')
            ->where(function ($q) {
                $q->whereIn('name', self::posBranchRoleNames())
                    ->orWhereHas('permissions', function ($p) {
                        $p->whereIn('name', self::permissionNames());
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Branch ids for branch_user pivot: checked allowed branches + assigned POS branch.
     *
     * @return int[]
     */
    public static function branchIdsForSyncFromRequest(\Illuminate\Http\Request $request): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $request->input('branch_ids', [])))));
        if ($request->filled('pos_branch_id')) {
            $ids[] = (int) $request->pos_branch_id;
            $ids = array_values(array_unique($ids));
        }

        return $ids;
    }

    /**
     * Apply pos_branch_id / validation for user create & update.
     *
     * @param  array<string, mixed>  $input
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public static function applyPosBranchToUserInput(
        array &$input,
        \Illuminate\Http\Request $request,
        Role $role,
        int $companyId,
        ?int $excludeUserId = null
    ): ?\Illuminate\Http\RedirectResponse {
        $needsBranch = self::roleRequiresPosBranch($role);

        if ($request->filled('pos_branch_id')) {
            $branchId = (int) $request->pos_branch_id;
            $request->validate(['pos_branch_id' => 'exists:branches,id']);
            $input['pos_branch_id'] = $branchId;

            if ($role->name === self::ROLE_KITCHEN) {
                $exists = User::where('created_by', $companyId)
                    ->where('pos_branch_id', $branchId)
                    ->whereHas('roles', fn ($q) => $q->where('name', self::ROLE_KITCHEN))
                    ->when($excludeUserId, fn ($q) => $q->where('id', '!=', $excludeUserId))
                    ->exists();
                if ($exists) {
                    return redirect()->back()->with('error', __('This branch already has a Kitchen user. Only one Kitchen per branch is allowed.'));
                }
            }
            if ($role->name === self::ROLE_BAR) {
                $exists = User::where('created_by', $companyId)
                    ->where('pos_branch_id', $branchId)
                    ->whereHas('roles', fn ($q) => $q->where('name', self::ROLE_BAR))
                    ->when($excludeUserId, fn ($q) => $q->where('id', '!=', $excludeUserId))
                    ->exists();
                if ($exists) {
                    return redirect()->back()->with('error', __('This branch already has a Bar user. Only one Bar per branch is allowed.'));
                }
            }

            return null;
        }

        if ($needsBranch) {
            return redirect()->back()->with('error', __('Please select a POS branch.'));
        }

        $input['pos_branch_id'] = null;

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function applyPosPinToUserInput(array &$input, \Illuminate\Http\Request $request, Role $role, int $companyId, ?int $excludeUserId = null): ?\Illuminate\Http\RedirectResponse
    {
        if (! self::roleRequiresPosBranch($role)) {
            $input['pos_pin'] = null;

            return null;
        }

        $pin = $request->pos_pin ? preg_replace('/\D/', '', (string) $request->pos_pin) : null;
        $input['pos_pin'] = ($pin !== '' && strlen($pin) >= 4 && strlen($pin) <= 8) ? $pin : null;
        if ($input['pos_pin'] === null) {
            return null;
        }

        $v = \Validator::make(
            ['pos_pin' => $input['pos_pin']],
            ['pos_pin' => \Illuminate\Validation\Rule::unique('users', 'pos_pin')->where('created_by', $companyId)->ignore($excludeUserId)]
        );
        if ($v->fails()) {
            return redirect()->back()->with('error', __('This PIN is already used by another user.'));
        }

        return null;
    }
}
