<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use App\Services\HotelPermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo company login with Hotel Management menu access.
 *
 * Email: company@example.com
 * Password: password
 *
 * Run: php artisan db:seed --class=CompanyUserSeeder
 */
class CompanyUserSeeder extends Seeder
{
    public function run(): void
    {
        HotelPermissionCatalog::ensurePermissionsExist();

        $role = Role::firstOrCreate(
            [
                'name' => 'company',
                'guard_name' => 'web',
            ],
            [
                'created_by' => 0,
            ]
        );

        // Additive only — does not remove existing company-role permissions.
        $hotelPermissions = Permission::query()
            ->where('guard_name', HotelPermissionCatalog::GUARD)
            ->whereIn('name', HotelPermissionCatalog::allNames())
            ->get();

        if ($hotelPermissions->isNotEmpty()) {
            $role->givePermissionTo($hotelPermissions);
        }

        $superAdmin = User::where('type', 'super admin')->orderBy('id')->first();
        $createdBy = $superAdmin?->id ?? 1;
        $planId = Plan::query()->orderBy('id')->value('id') ?? 1;

        do {
            $referralCode = (string) random_int(100000, 999999);
        } while (User::where('referral_code', $referralCode)->exists());

        $user = User::firstOrCreate(
            ['email' => 'company@example.com'],
            [
                'name' => 'Company',
                'password' => Hash::make('password'),
                'type' => 'company',
                'default_pipeline' => 1,
                'plan' => $planId,
                'lang' => 'en',
                'avatar' => '',
                'mode' => 'light',
                'created_by' => $createdBy,
                'email_verified_at' => now(),
                'referral_code' => $referralCode,
                'is_active' => 1,
                'is_enable_login' => 1,
                'delete_status' => 1,
            ]
        );

        $user->password = Hash::make('password');
        $user->type = 'company';
        $user->is_active = 1;
        $user->is_enable_login = 1;
        $user->email_verified_at = $user->email_verified_at ?? now();

        if (empty($user->plan)) {
            $user->plan = $planId;
        }

        if (empty($user->default_company_id)) {
            $user->default_company_id = $user->id;
        }

        $user->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        if (Schema::hasTable('company_user')) {
            $exists = DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $user->id)
                ->exists();

            if (! $exists) {
                DB::table('company_user')->insert([
                    'user_id' => $user->id,
                    'company_id' => $user->id,
                    'role' => 'company',
                    'status' => 'active',
                    'is_default' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Company user ready: company@example.com / password (Hotel module enabled)');
    }
}
