<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiCompanyContextTest extends TestCase
{
    use DatabaseTransactions;

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
}
