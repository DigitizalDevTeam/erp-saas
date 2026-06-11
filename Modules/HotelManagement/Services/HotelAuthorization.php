<?php

namespace Modules\HotelManagement\Services;

use App\Models\User;
use App\Services\HotelPermissionCatalog;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Single source of truth for hotel permission checks (menu + controllers).
 *
 * Recommended roles (assign via User → Roles → Hotel tab):
 * - Hotel Manager: manage hotel
 * - Front desk (full): manage hotel frontdesk + show hotel reservation
 * - Front desk (view): show hotel frontdesk + show hotel reservation + show hotel folio
 * - Reservations: manage hotel reservation (or show + manage mix)
 * - Housekeeping lead: manage hotel frontdesk (housekeeping + minibar + night audit view)
 * - Rooms setup: manage hotel room
 * - Owner / reports: show hotel dashboard
 */
class HotelAuthorization
{
    public static function user(Authenticatable|null $user = null): User
    {
        $user = $user ?? auth()->user();
        if (! $user instanceof User) {
            throw new HttpException(403, __('Permission denied.'));
        }

        return $user;
    }

    public static function hasAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Any hotel permission — show parent "Hotel Management" menu. */
    public static function canAccessModule(User|null $user = null): bool
    {
        $user = self::user($user);

        return self::hasAny($user, HotelPermissionCatalog::allNames());
    }

    public static function hasFullAccess(User|null $user = null): bool
    {
        return self::user($user)->can('manage hotel');
    }

    public static function menuDashboard(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('show hotel dashboard');
    }

    public static function menuReservations(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel reservation')
            || $user->can('show hotel reservation');
    }

    public static function menuFrontdesk(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel frontdesk')
            || $user->can('show hotel frontdesk')
            || $user->can('show hotel folio');
    }

    public static function menuGuestMap(User|null $user = null): bool
    {
        return self::viewGuestMap($user);
    }

    public static function menuHousekeeping(User|null $user = null): bool
    {
        return self::viewHousekeeping($user);
    }

    public static function menuNightAudit(User|null $user = null): bool
    {
        return self::viewNightAudit($user);
    }

    public static function menuAccounting(User|null $user = null): bool
    {
        return self::manageAccounting($user);
    }

    public static function menuReports(User|null $user = null): bool
    {
        return self::viewReports($user);
    }

    public static function menuCalendar(User|null $user = null): bool
    {
        return self::menuReservations($user);
    }

    public static function menuGroups(User|null $user = null): bool
    {
        return self::menuReservations($user);
    }

    public static function menuOta(User|null $user = null): bool
    {
        return self::manageOta($user);
    }

    public static function menuRoomsSetup(User|null $user = null): bool
    {
        return self::viewRooms($user);
    }

    public static function viewDashboard(User|null $user = null): bool
    {
        return self::menuDashboard($user);
    }

    public static function viewReservations(User|null $user = null): bool
    {
        return self::menuReservations($user);
    }

    public static function manageReservations(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel reservation');
    }

    public static function viewFrontdesk(User|null $user = null): bool
    {
        return self::menuFrontdesk($user);
    }

    public static function manageFrontdesk(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel frontdesk');
    }

    public static function viewFolio(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel frontdesk')
            || $user->can('show hotel folio');
    }

    public static function manageFolio(User|null $user = null): bool
    {
        return self::manageFrontdesk($user);
    }

    public static function canForceCheckout(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('force hotel checkout');
    }

    public static function viewGuestMap(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel reservation')
            || $user->can('show hotel reservation')
            || $user->can('manage hotel frontdesk')
            || $user->can('show hotel frontdesk');
    }

    public static function viewHousekeeping(User|null $user = null): bool
    {
        return self::viewFrontdesk($user);
    }

    public static function manageHousekeeping(User|null $user = null): bool
    {
        return self::manageFrontdesk($user);
    }

    public static function viewNightAudit(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel frontdesk')
            || $user->can('show hotel frontdesk');
    }

    public static function manageNightAudit(User|null $user = null): bool
    {
        return self::manageFrontdesk($user);
    }

    public static function manageAccounting(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel frontdesk');
    }

    public static function viewReports(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('show hotel dashboard')
            || $user->can('manage hotel frontdesk');
    }

    public static function viewRooms(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel room')
            || $user->can('show hotel room');
    }

    public static function manageRooms(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel room');
    }

    public static function manageOta(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel reservation');
    }

    public static function manageBookingSite(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel') || $user->can('manage hotel reservation');
    }

    public static function menuBookingSite(User|null $user = null): bool
    {
        return self::manageBookingSite($user);
    }

    public static function viewGroups(User|null $user = null): bool
    {
        return self::viewReservations($user);
    }

    public static function manageGroups(User|null $user = null): bool
    {
        return self::manageReservations($user);
    }

    public static function viewCalendar(User|null $user = null): bool
    {
        return self::viewReservations($user);
    }

    public static function manageRoomConsumption(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->can('manage hotel')
            || $user->can('manage hotel room')
            || $user->can('manage hotel frontdesk');
    }

    public static function accessPosIntegration(User|null $user = null): bool
    {
        $user = self::user($user);

        return $user->canManagePos()
            || $user->can('manage hotel')
            || $user->can('manage hotel frontdesk');
    }

    public static function abortUnless(bool $allowed, int $status = 403, string|null $message = null): void
    {
        if (! $allowed) {
            throw new HttpException($status, $message ?? __('Permission denied.'));
        }
    }
}
