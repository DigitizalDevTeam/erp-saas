<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserForceVerifyEmailTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_can_force_verify_a_company_email(): void
    {
        $superAdmin = User::factory()->create(['type' => 'super admin']);
        $company = User::factory()->unverified()->create(['type' => 'company']);

        $response = $this->actingAs($superAdmin)
            ->post(route('users.force.verify', $company->id));

        $response->assertRedirect();
        $this->assertNotNull($company->fresh()->email_verified_at);
    }

    public function test_force_verify_an_already_verified_company_is_a_no_op(): void
    {
        $superAdmin = User::factory()->create(['type' => 'super admin']);
        $company = User::factory()->create(['type' => 'company']);
        $verifiedAt = $company->email_verified_at;

        $response = $this->actingAs($superAdmin)
            ->post(route('users.force.verify', $company->id));

        $response->assertRedirect();
        $this->assertEquals($verifiedAt, $company->fresh()->email_verified_at);
    }

    public function test_guest_cannot_force_verify_an_email(): void
    {
        $company = User::factory()->unverified()->create(['type' => 'company']);

        $this->post(route('users.force.verify', $company->id));

        $this->assertNull($company->fresh()->email_verified_at);
    }
}
