<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    if (! Schema::hasTable('company_user')) {
      return;
    }

    // Rename confusing company role that collides with platform "super admin".
    $superAdminRoles = DB::table('roles')
      ->whereRaw('LOWER(name) = ?', ['super admin'])
      ->where('created_by', '>', 0)
      ->get();

    foreach ($superAdminRoles as $role) {
      $companyId = (int) $role->created_by;
      $hasAdministrador = DB::table('roles')
        ->where('created_by', $companyId)
        ->where('name', 'Administrador')
        ->exists();

      $newName = $hasAdministrador ? 'Company Administrator' : 'Administrador';

      DB::table('roles')->where('id', $role->id)->update([
        'name' => $newName,
        'updated_at' => now(),
      ]);

      DB::table('company_user')
        ->where('role', $role->name)
        ->update(['role' => $newName, 'updated_at' => now()]);

      DB::table('users')
        ->where('type', $role->name)
        ->where('type', '!=', 'super admin')
        ->update(['type' => $newName, 'updated_at' => now()]);
    }

    // Fix users.type polluted with SUPER ADMIN (uppercase) — not platform super admin.
    DB::table('users')
      ->where('type', 'SUPER ADMIN')
      ->update(['type' => 'Administrador', 'updated_at' => now()]);

    // Backfill company_user.role from users.type where a matching company role exists.
    $memberships = DB::table('company_user')
      ->join('users', 'users.id', '=', 'company_user.user_id')
      ->whereNull('company_user.role')
      ->whereNotIn('users.type', ['super admin', 'company', 'client'])
      ->select([
        'company_user.id',
        'company_user.company_id',
        'company_user.user_id',
        'users.type as user_type',
      ])
      ->get();

    foreach ($memberships as $row) {
      $roleName = DB::table('roles')
        ->where('created_by', (int) $row->company_id)
        ->where('name', (string) $row->user_type)
        ->value('name');

      if (! $roleName) {
        $roleName = DB::table('roles')
          ->where('created_by', (int) $row->company_id)
          ->where('name', 'Administrador')
          ->value('name')
          ?? DB::table('roles')
            ->where('created_by', (int) $row->company_id)
            ->orderBy('id')
            ->value('name');
      }

      if ($roleName) {
        DB::table('company_user')->where('id', $row->id)->update([
          'role' => $roleName,
          'updated_at' => now(),
        ]);
      }
    }

    // Re-apply single role per user based on default company's membership.
    $staff = DB::table('users')
      ->whereNotIn('type', ['super admin', 'company', 'client'])
      ->whereNotNull('default_company_id')
      ->pluck('default_company_id', 'id');

    foreach ($staff as $userId => $defaultCompanyId) {
      $membership = DB::table('company_user')
        ->where('user_id', (int) $userId)
        ->where('company_id', (int) $defaultCompanyId)
        ->where('status', 'active')
        ->first();

      if (! $membership || empty($membership->role)) {
        continue;
      }

      $roleId = DB::table('roles')
        ->where('created_by', (int) $defaultCompanyId)
        ->where('name', (string) $membership->role)
        ->value('id');

      if (! $roleId) {
        continue;
      }

      DB::table('model_has_roles')
        ->where('model_type', 'App\\Models\\User')
        ->where('model_id', (int) $userId)
        ->delete();

      DB::table('model_has_roles')->insert([
        'role_id' => (int) $roleId,
        'model_type' => 'App\\Models\\User',
        'model_id' => (int) $userId,
      ]);

      DB::table('users')->where('id', (int) $userId)->update([
        'type' => (string) $membership->role,
        'updated_at' => now(),
      ]);
    }
  }

  public function down(): void
  {
    // Data repair migration — no rollback.
  }
};
