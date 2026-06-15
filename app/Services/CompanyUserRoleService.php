<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class CompanyUserRoleService
{
  /**
   * System-only user types (platform). Must never be copied from a company role name.
   */
  public static function reservedSystemTypes(): array
  {
    return ['super admin', 'company', 'client'];
  }

  public static function isReservedSystemType(string $name): bool
  {
    return in_array(strtolower(trim($name)), self::reservedSystemTypes(), true);
  }

  /**
   * True for the single platform super admin account type (exact match only).
   */
  public static function isPlatformSuperAdmin(User $user): bool
  {
    return $user->type === 'super admin';
  }

  /**
   * Role names that must not be stored on users.type (conflicts with platform type).
   */
  public static function isForbiddenUserType(string $roleName): bool
  {
    $normalized = strtolower(trim($roleName));

    return in_array($normalized, self::reservedSystemTypes(), true);
  }

  /**
   * Map a company role name to a safe users.type value.
   */
  public static function userTypeFromRoleName(string $roleName): string
  {
    $roleName = trim($roleName);
    if ($roleName === '') {
      return 'Employee';
    }

    if (self::isForbiddenUserType($roleName)) {
      return 'Administrador';
    }

    return $roleName;
  }

  /**
   * Apply the membership role for a company: sync Spatie role + users.type for that tenant.
   */
  public static function applyRoleForCompany(User $user, int $companyId): void
  {
    if (self::isPlatformSuperAdmin($user) || $user->type === 'company' || $user->type === 'client') {
      return;
    }

    if ($companyId <= 0 || ! Schema::hasTable('company_user')) {
      return;
    }

    $membership = DB::table('company_user')
      ->where('user_id', $user->id)
      ->where('company_id', $companyId)
      ->where('status', 'active')
      ->first();

    if (! $membership) {
      return;
    }

    $roleName = ! empty($membership->role) ? (string) $membership->role : null;

    $role = null;
    if ($roleName) {
      $role = Role::where('created_by', $companyId)->where('name', $roleName)->first();
    }

    if (! $role && ! empty($user->type) && ! self::isForbiddenUserType($user->type)) {
      $role = Role::where('created_by', $companyId)->where('name', $user->type)->first();
      if ($role) {
        self::storeMembershipRole((int) $user->id, $companyId, $role->name);
      }
    }

    if (! $role) {
      return;
    }

    $user->syncRoles([$role]);
    $user->type = self::userTypeFromRoleName($role->name);
    $user->save();
  }

  public static function storeMembershipRole(int $userId, int $companyId, string $roleName): void
  {
    if (! Schema::hasTable('company_user')) {
      return;
    }

    DB::table('company_user')
      ->where('user_id', $userId)
      ->where('company_id', $companyId)
      ->update([
        'role' => trim($roleName),
        'updated_at' => now(),
      ]);
  }

  /**
   * If default company is missing or login-disabled, point default to first accessible company.
   */
  public static function normalizeDefaultCompany(User $user): ?int
  {
    if (self::isPlatformSuperAdmin($user) || $user->type === 'company' || $user->type === 'client') {
      return null;
    }

    if (! Schema::hasTable('company_user')) {
      return null;
    }

    $currentDefault = (int) ($user->default_company_id ?? 0);
    if ($currentDefault > 0 && $user->canAccessCompany($currentDefault)) {
      return $currentDefault;
    }

    $nextDefault = $user->firstActiveCompanyId();
    if (! $nextDefault) {
      return null;
    }

    if ((int) ($user->default_company_id ?? 0) !== (int) $nextDefault) {
      $user->default_company_id = (int) $nextDefault;
      $user->save();

      DB::table('company_user')
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->update(['is_default' => false, 'updated_at' => now()]);

      DB::table('company_user')
        ->where('user_id', $user->id)
        ->where('company_id', (int) $nextDefault)
        ->update(['is_default' => true, 'updated_at' => now()]);
    }

    return (int) $nextDefault;
  }

  /**
   * Keep is_enable_login in sync for multi-company staff (password required).
   */
  public static function refreshLoginEligibility(User $user): void
  {
    if (in_array($user->type, ['super admin', 'company', 'client'], true)) {
      return;
    }

    if (empty($user->password)) {
      return;
    }

    if (! Schema::hasTable('company_user')) {
      return;
    }

    $activeMembershipCount = (int) DB::table('company_user')
      ->where('user_id', $user->id)
      ->where('status', 'active')
      ->count();

    if ($activeMembershipCount > 1 && (int) $user->is_enable_login === 0) {
      $user->is_enable_login = 1;
      $user->save();
    }
  }

  /**
   * Fields safe to update when linking an existing user to another company.
   */
  public static function linkableUserFields(array $payload): array
  {
    return collect($payload)->only([
      'name',
      'lang',
      'pos_branch_id',
      'pos_pin',
      'avatar',
    ])->all();
  }
}
