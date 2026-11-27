<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Login as User" in the Users list (company admins).
 *
 * The dropdown option already existed for super admin ("Login As Company")
 * but was hidden for company admins. The route itself had no ownership
 * check at all, so this also locks it down: a company admin may only
 * impersonate users from their own company.
 */
class CompanyLoginAsUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        // usersForCompany() eager-loads currentPlan.
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    private function makeUser(array $attrs): User
    {
        $user = new User($attrs);
        $user->save();

        return $user;
    }

    public function test_company_admin_can_login_as_their_own_staff_user(): void
    {
        $company = $this->makeUser([
            'name' => 'Hoya Hoya', 'email' => 'company@test.local',
            'type' => 'company', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        $staff = $this->makeUser([
            'name' => 'Claudio Pagula', 'email' => 'staff@test.local',
            'type' => 'Stock Control', 'created_by' => $company->id,
        ]);

        $response = $this->actingAs($company)
            ->get(route('login.with.company', $staff->id));

        $response->assertRedirect('/account-dashboard');
        $this->assertEquals($company->id, session('impersonated_by'));
    }

    public function test_company_admin_cannot_login_as_another_companys_user(): void
    {
        $company = $this->makeUser([
            'name' => 'Hoya Hoya', 'email' => 'company@test.local',
            'type' => 'company', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        $otherCompany = $this->makeUser([
            'name' => 'Other Co', 'email' => 'other@test.local',
            'type' => 'company', 'created_by' => 3, 'default_company_id' => 3,
        ]);
        $foreignStaff = $this->makeUser([
            'name' => 'Foreign Staff', 'email' => 'foreign@test.local',
            'type' => 'Stock Control', 'created_by' => $otherCompany->id,
        ]);

        $response = $this->actingAs($company)
            ->from('/users')
            ->get(route('login.with.company', $foreignStaff->id));

        $response->assertRedirect('/users');
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertNull(session('impersonated_by'));
    }

    public function test_super_admin_can_still_login_as_anyone(): void
    {
        $superAdmin = $this->makeUser([
            'name' => 'Super Admin', 'email' => 'super@test.local',
            'type' => 'super admin', 'created_by' => 1,
        ]);
        $staff = $this->makeUser([
            'name' => 'Some Staff', 'email' => 'staff@test.local',
            'type' => 'Stock Control', 'created_by' => 99,
        ]);

        $response = $this->actingAs($superAdmin)
            ->get(route('login.with.company', $staff->id));

        $response->assertRedirect('/account-dashboard');
        $this->assertEquals($superAdmin->id, session('impersonated_by'));
    }

    public function test_staff_user_cannot_use_the_login_as_route(): void
    {
        $company = $this->makeUser([
            'name' => 'Hoya Hoya', 'email' => 'company@test.local',
            'type' => 'company', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        $staff = $this->makeUser([
            'name' => 'Claudio Pagula', 'email' => 'staff@test.local',
            'type' => 'Stock Control', 'created_by' => $company->id,
        ]);
        $otherStaff = $this->makeUser([
            'name' => 'Other Staff', 'email' => 'other-staff@test.local',
            'type' => 'Stock Control', 'created_by' => $company->id,
        ]);

        $response = $this->actingAs($staff)
            ->from('/users')
            ->get(route('login.with.company', $otherStaff->id));

        $response->assertRedirect('/users');
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertNull(session('impersonated_by'));
    }
}
