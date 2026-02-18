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
            self::ROLE_BAR => [self::PERMISSION_WAITER, 'show pos dashboard'],
            self::ROLE_KITCHEN => [self::PERMISSION_WAITER, 'show pos dashboard'],
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
}
