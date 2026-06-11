<?php

namespace Tests\Unit\HotelManagement;

use App\Models\User;
use Mockery;
use Modules\HotelManagement\Services\HotelAuthorization;
use PHPUnit\Framework\TestCase;

class HotelAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_front_desk_view_can_access_frontdesk_but_not_manage_rooms(): void
    {
        $user = $this->mockUser([
            'show hotel frontdesk' => true,
            'show hotel folio' => true,
            'manage hotel room' => false,
            'manage hotel' => false,
        ]);

        $this->assertTrue(HotelAuthorization::viewFrontdesk($user));
        $this->assertFalse(HotelAuthorization::manageFrontdesk($user));
        $this->assertFalse(HotelAuthorization::manageRooms($user));
    }

    public function test_housekeeping_lead_matches_manage_frontdesk(): void
    {
        $user = $this->mockUser([
            'manage hotel frontdesk' => true,
            'manage hotel' => false,
        ]);

        $this->assertTrue(HotelAuthorization::manageHousekeeping($user));
        $this->assertTrue(HotelAuthorization::manageNightAudit($user));
    }

    public function test_manager_has_full_access_via_manage_hotel(): void
    {
        $user = $this->mockUser(['manage hotel' => true]);

        $this->assertTrue(HotelAuthorization::hasFullAccess($user));
        $this->assertTrue(HotelAuthorization::manageReservations($user));
        $this->assertTrue(HotelAuthorization::canForceCheckout($user));
    }

    public function test_force_checkout_requires_explicit_permission(): void
    {
        $user = $this->mockUser([
            'manage hotel frontdesk' => true,
            'force hotel checkout' => false,
            'manage hotel' => false,
        ]);

        $this->assertFalse(HotelAuthorization::canForceCheckout($user));
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    protected function mockUser(array $permissions): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')
            ->andReturnUsing(fn (string $name) => (bool) ($permissions[$name] ?? false));

        return $user;
    }
}
