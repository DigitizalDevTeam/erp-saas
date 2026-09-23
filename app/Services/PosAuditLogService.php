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
     * Restaurant POS actor (PIN user on terminal, or company login).
     */
    public static function restaurantPosActor(): ?User
    {
        return PosActorService::restaurantActor();
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
     * Manager / company admin may void restaurant lines without an extra password.
     */
    public static function canVoidRestaurantLineDirectly(User $actor): bool
    {
        return $actor->can('pos restaurant manager')
            || $actor->can('manage pos')
            || in_array($actor->type, ['company', 'super admin'], true);
    }

    /**
     * Admin / company admin may delete sent lines from the restaurant cart.
     */
    public static function canDeleteSentRestaurantLines(User $actor): bool
    {
        return $actor->can('manage pos')
            || in_array($actor->type, ['company', 'super admin'], true);
    }

    /**
     * Whether the current POS actor may attempt to void sent restaurant lines
     * (managers/admins directly; cashier/waiter with manager password).
     */
    public static function canVoidRestaurantLine(User $actor): bool
    {
        return $actor->canManagePos();
    }

    /**
     * Verify Manager/Admin account password or Manager/Admin POS PIN for the company.
     */
    public static function verifyManagerAdminOrPosPassword(Request $request, int $creatorId): bool
    {
        $password = $request->input('manager_password');
        if ($password === null || $password === '') {
            return false;
        }

        $pinCandidate = preg_replace('/\D/', '', (string) $password);
        $authorizers = User::query()
            ->where(function ($q) use ($creatorId) {
                $q->where('created_by', $creatorId)->orWhere('id', $creatorId);
            })
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->where('delete_status', 1)->orWhereNull('delete_status');
            })
            ->get();

        foreach ($authorizers as $user) {
            if (! self::canVoidRestaurantLineDirectly($user)) {
                continue;
            }
            if (! empty($user->password) && Hash::check((string) $password, (string) $user->password)) {
                return true;
            }
            if ($pinCandidate !== '' && ! empty($user->pos_pin) && (string) $user->pos_pin === (string) $pinCandidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authorize void: managers/admins pass immediately; others need a valid manager/admin/POS password.
     */
    public static function authorizeVoidRestaurantLine(Request $request, User $actor): bool
    {
        if (! self::canVoidRestaurantLine($actor)) {
            return false;
        }
        if (self::canVoidRestaurantLineDirectly($actor)) {
            return true;
        }

        return self::verifyManagerAdminOrPosPassword($request, (int) $actor->creatorId());
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
        $actor = PosActorService::restaurantActor() ?? $user;
        return PosAuditLog::create([
            'created_by' => $user ? $user->creatorId() : 0,
            'user_id' => $actor ? $actor->id : 0,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip' => request()->ip(),
        ]);
    }
}
