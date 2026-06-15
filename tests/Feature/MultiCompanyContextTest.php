<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MultiCompanyContextTest extends TestCase
{
    use DatabaseTransactions;

    public function test_company_user_index_excludes_other_company_accounts(): void
    {
        $companyA = User::factory()->create(['type' => 'company']);
        $companyB = User::factory()->create(['type' => 'company']);
        $employee = User::factory()->create([
            'type' => 'Employee',
            'created_by' => $companyA->id,
        ]);

        Permission::firstOrCreate(['name' => 'manage user', 'guard_name' => 'web']);
        $companyA->givePermissionTo('manage user');

        DB::table('company_user')->insert([
            [
                'company_id' => $companyA->id,
                'user_id' => $companyA->id,
                'status' => 'active',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $companyA->id,
                'user_id' => $companyB->id,
                'status' => 'active',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $companyA->id,
                'user_id' => $employee->id,
                'status' => 'active',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        session(['active_company_id' => $companyA->id]);
        $this->actingAs($companyA);

        $response = $this->get(route('users.index'));

        $response->assertOk();
        $response->assertSee($companyA->name, false);
        $response->assertSee($employee->name, false);
        $response->assertDontSee($companyB->name, false);
    }

    public function test_user_uses_default_company_from_membership(): void
    {
        $companyA = User::factory()->create(['type' => 'company']);
        $companyB = User::factory()->create(['type' => 'company']);
        $staff = User::factory()->create([
            'type' => 'staff',
            'created_by' => $companyA->id,
            'default_company_id' => $companyB->id,
        ]);

        DB::table('company_user')->insert([
            [
                'company_id' => $companyA->id,
                'user_id' => $staff->id,
                'status' => 'active',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $companyB->id,
                'user_id' => $staff->id,
                'status' => 'active',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame($companyB->id, $staff->activeCompanyId());
        $this->assertSame($companyB->id, $staff->creatorId());
    }

    public function test_user_can_switch_active_company_when_membership_exists(): void
    {
        $companyA = User::factory()->create(['type' => 'company']);
        $companyB = User::factory()->create(['type' => 'company']);
        $staff = User::factory()->create([
            'type' => 'staff',
            'created_by' => $companyA->id,
            'default_company_id' => $companyA->id,
        ]);

        DB::table('company_user')->insert([
            ['company_id' => $companyA->id, 'user_id' => $staff->id, 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyB->id, 'user_id' => $staff->id, 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($staff);
        $this->assertTrue($staff->setActiveCompany($companyB->id));
        $this->assertSame($companyB->id, $staff->activeCompanyId());
    }

    public function test_user_role_switches_with_active_company(): void
    {
        $companyA = User::factory()->create(['type' => 'company']);
        $companyB = User::factory()->create(['type' => 'company']);

        $roleA = \Spatie\Permission\Models\Role::create(['name' => 'RoleA', 'guard_name' => 'web', 'created_by' => $companyA->id]);
        $roleB = \Spatie\Permission\Models\Role::create(['name' => 'RoleB', 'guard_name' => 'web', 'created_by' => $companyB->id]);

        $staff = User::factory()->create([
            'type' => 'RoleA',
            'created_by' => $companyA->id,
            'default_company_id' => $companyA->id,
        ]);

        DB::table('company_user')->insert([
            ['company_id' => $companyA->id, 'user_id' => $staff->id, 'role' => 'RoleA', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $companyB->id, 'user_id' => $staff->id, 'role' => 'RoleB', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        \App\Services\CompanyUserRoleService::applyRoleForCompany($staff, $companyA->id);
        $staff->refresh();
        $this->assertSame('RoleA', $staff->type);
        $this->assertTrue($staff->hasRole('RoleA'));

        \App\Services\CompanyUserRoleService::applyRoleForCompany($staff, $companyB->id);
        $staff->refresh();
        $this->assertSame('RoleB', $staff->type);
        $this->assertTrue($staff->hasRole('RoleB'));
    }

    public function test_forbidden_role_name_maps_to_safe_user_type(): void
    {
        $this->assertSame('Administrador', \App\Services\CompanyUserRoleService::userTypeFromRoleName('SUPER ADMIN'));
        $this->assertSame('Administrador', \App\Services\CompanyUserRoleService::userTypeFromRoleName('super admin'));
        $this->assertTrue(\App\Services\CompanyUserRoleService::isReservedSystemType('Super Admin'));
    }
}
