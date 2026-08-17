<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PosLoginUserService;
use Mockery;
use Tests\TestCase;

class PosLoginUserServiceTest extends TestCase
{
    private PosLoginUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PosLoginUserService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_display_label_uses_name_when_present(): void
    {
        $user = new User([
            'name' => 'Jane Waiter',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('Jane Waiter', PosLoginUserService::displayLabel($user));
    }

    public function test_display_label_falls_back_to_email_when_name_empty(): void
    {
        $user = new User([
            'name' => '   ',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('jane@example.com', PosLoginUserService::displayLabel($user));
    }

    public function test_user_with_pos_pin_and_waiter_role_is_eligible(): void
    {
        $user = $this->mockEligibleUser([
            'pos restaurant waiter' => true,
        ], [
            'pos_pin' => '1234',
            'is_active' => 1,
            'delete_status' => 1,
            'is_disable' => 1,
            'type' => 'Employee',
            'is_enable_login' => 1,
        ]);

        $this->assertTrue($this->service->isEligibleForPosLogin($user));
    }

    public function test_user_without_pos_pin_is_not_eligible(): void
    {
        $user = $this->mockEligibleUser([
            'pos restaurant waiter' => true,
        ], [
            'pos_pin' => '',
            'is_active' => 1,
            'delete_status' => 1,
            'is_disable' => 1,
            'type' => 'Employee',
            'is_enable_login' => 1,
        ]);

        $this->assertFalse($this->service->isEligibleForPosLogin($user));
    }

    public function test_user_with_pin_but_no_pos_permission_is_not_eligible(): void
    {
        $user = $this->mockEligibleUser([], [
            'pos_pin' => '1234',
            'is_active' => 1,
            'delete_status' => 1,
            'is_disable' => 1,
            'type' => 'Employee',
            'is_enable_login' => 1,
        ]);

        $this->assertFalse($this->service->isEligibleForPosLogin($user));
    }

    /**
     * @param  array<string, bool>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    private function mockEligibleUser(array $permissions, array $attributes): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill($attributes);
        $user->shouldReceive('can')
            ->andReturnUsing(fn (string $name) => (bool) ($permissions[$name] ?? false));
        $user->shouldReceive('canManagePos')
            ->andReturnUsing(function () use ($permissions) {
                return ($permissions['manage pos'] ?? false)
                    || ($permissions['pos restaurant full'] ?? false)
                    || ($permissions['pos restaurant waiter'] ?? false)
                    || ($permissions['pos restaurant manager'] ?? false);
            });

        return $user;
    }
}
