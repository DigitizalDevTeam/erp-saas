<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves who is acting on POS (PIN user on restaurant terminal vs ERP login).
 */
class PosActorService
{
    /** After "Switch user", even company ERP login must re-enter a PIN. */
    public const SESSION_REQUIRE_PIN = 'pos_restaurant_require_pin';

    public static function isCompanyLogin(): bool
    {
        $user = Auth::user();

        return $user && $user->type === 'company';
    }

    public static function requireRestaurantPinEntry(): void
    {
        session([self::SESSION_REQUIRE_PIN => true]);
    }

    public static function clearRestaurantPinRequirement(): void
    {
        session()->forget(self::SESSION_REQUIRE_PIN);
    }

    /**
     * Restaurant terminal actor: active PIN user when set; else company owner may use ERP login
     * until they switch user (then PIN is required for everyone).
     */
    public static function restaurantActor(): ?User
    {
        $sessionUser = Auth::user();
        if (! $sessionUser) {
            return null;
        }

        $pinId = session('pos_pin_user_id');
        if ($pinId) {
            $pinUser = User::find($pinId);
            if ($pinUser && (int) $pinUser->created_by === (int) $sessionUser->creatorId()) {
                return $pinUser;
            }
            session()->forget('pos_pin_user_id');
        }

        if (session(self::SESSION_REQUIRE_PIN)) {
            return null;
        }

        if (self::isCompanyLogin()) {
            return $sessionUser;
        }

        return null;
    }

    /**
     * User id for orders, shifts, and audit while on POS (PIN when set, else ERP user).
     */
    public static function posActorId(): int
    {
        $restaurant = self::restaurantActor();
        if ($restaurant) {
            return (int) $restaurant->id;
        }

        return (int) Auth::id();
    }

    /**
     * Clear PIN and restaurant cart state when switching users or leaving POS.
     */
    public static function clearRestaurantTerminalSession(): void
    {
        session()->forget([
            'pos_pin_user_id',
            'pos_restaurant_cart',
            'pos',
        ]);
    }
}
