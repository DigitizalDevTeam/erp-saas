<?php

namespace App\Services;

use App\Models\PosAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PosAuditLogService
{
    /**
     * Restaurant POS actor: PIN user when set, otherwise the logged-in user.
     */
    public static function restaurantPosActor(): ?User
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $id = session('pos_pin_user_id', $user->id);
        $actor = User::find($id);
        if ($actor && (int) $actor->created_by === (int) $user->creatorId()) {
            return $actor;
        }

        return $user;
    }

    /**
     * Manager, cashier (full), or company POS admin.
     */
    public static function canAuthorizeRestaurantSensitiveAction(User $actor): bool
    {
        return $actor->can('pos restaurant manager')
            || $actor->can('pos restaurant full')
            || $actor->can('manage pos');
    }

    /**
     * Whether the current POS actor may void sent restaurant lines.
     */
    public static function canVoidRestaurantLine(User $actor): bool
    {
        return $actor->canManagePos() && self::canAuthorizeRestaurantSensitiveAction($actor);
    }

    /**
     * Phase 4: Manager authorization for sensitive actions.
     * When a supervisor/manager is already signed in via POS PIN, no extra password is required.
     */
    public static function requireManagerPassword(Request $request): bool
    {
        $sessionUser = Auth::user();
        if (! $sessionUser) {
            return false;
        }

        $actor = self::restaurantPosActor();
        if (! $actor || ! self::canAuthorizeRestaurantSensitiveAction($actor)) {
            return false;
        }

        $pinUserId = session('pos_pin_user_id');
        if ($pinUserId && (int) $pinUserId === (int) $actor->id) {
            return true;
        }

        if (! $pinUserId && Auth::id() === $actor->id) {
            return true;
        }

        $password = $request->input('manager_password');
        if ($password === null || $password === '') {
            return false;
        }

        return Hash::check($password, (string) $actor->password);
    }

    public static function log(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        array $meta = []
    ): PosAuditLog {
        $user = Auth::user();
        $actor = self::restaurantPosActor();
        return PosAuditLog::create([
            'created_by' => $user ? $user->creatorId() : 0,
            'user_id' => $actor ? $actor->id : ($user ? $user->id : 0),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip' => request()->ip(),
        ]);
    }
}
