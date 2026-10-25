<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Manager authorization for selling below zero stock.
 *
 * A sale that would take stock below zero for a product that does NOT
 * allow negative stock may still proceed when a manager authorizes it:
 * - a manager (or the company owner) signed in on the terminal proceeds
 *   directly, no popup;
 * - anyone else must enter a manager's account password or POS PIN.
 *
 * The authorization is recorded on the sale line
 * (negative_stock_authorized_by) so the later stock deduction may take
 * that one line below zero, and so the audit log shows who approved it.
 * Batch-tracked and identifier-tracked products can never be authorized:
 * lots and serials cannot go negative.
 */
class NegativeStockAuthorizationService
{
    /** A grant only opens the add-to-cart gate, and only for a short while. */
    public const GRANT_TTL_MINUTES = 10;

    public const SESSION_KEY = 'neg_stock_auth_grants';

    /**
     * Current POS actor (PIN user on a restaurant terminal, else ERP login).
     */
    public static function actor(): ?User
    {
        return PosActorService::restaurantActor() ?? Auth::user();
    }

    /**
     * Whether this user may authorize without entering a password:
     * managers, POS admins, and the company owner (same set as void approval).
     */
    public static function canAuthorizeDirectly(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return PosAuditLogService::canVoidRestaurantLineDirectly($user);
    }

    /**
     * The authorizing manager for this request, or null: the actor when they
     * may authorize directly, else the manager whose password/PIN was given.
     */
    public static function authorizerForRequest(Request $request, int $creatorId): ?User
    {
        $actor = self::actor();
        if (self::canAuthorizeDirectly($actor)) {
            return $actor;
        }

        $password = (string) $request->input('manager_password', '');
        if ($password === '') {
            return null;
        }

        return self::verifyManagerPassword($password, $creatorId);
    }

    /**
     * Find the manager/admin whose account password or POS PIN matches.
     * Returns the user (for the audit trail), not just a boolean.
     */
    public static function verifyManagerPassword(string $password, int $creatorId): ?User
    {
        if ($password === '') {
            return null;
        }

        $pinCandidate = preg_replace('/\D/', '', $password);

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
            if (! PosAuditLogService::canVoidRestaurantLineDirectly($user)) {
                continue;
            }
            if (! empty($user->password) && Hash::check($password, (string) $user->password)) {
                return $user;
            }
            if ($pinCandidate !== '' && ! empty($user->pos_pin) && (string) $user->pos_pin === (string) $pinCandidate) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Products whose stock can never go negative, even with authorization:
     * lots and serials cannot be oversold.
     */
    public static function neverNegative($product): bool
    {
        return (bool) ($product->batch_tracking ?? false)
            || (bool) ($product->identifier_tracking ?? false);
    }

    public static function storeGrant(int $productId, ?int $variantId, int $managerId): void
    {
        $grants = session(self::SESSION_KEY, []);
        if (! is_array($grants)) {
            $grants = [];
        }
        $grants[] = [
            'product_id' => $productId,
            'variant_id' => $variantId ? (int) $variantId : null,
            'manager_id' => $managerId,
            'expires_at' => now()->addMinutes(self::GRANT_TTL_MINUTES)->timestamp,
        ];
        session([self::SESSION_KEY => $grants]);
    }

    /**
     * Unexpired grant for this product/variant, if the manager approved one
     * recently. Expired grants are pruned on read.
     */
    public static function grantFor(int $productId, ?int $variantId): ?array
    {
        $grants = session(self::SESSION_KEY, []);
        if (! is_array($grants)) {
            return null;
        }
        $now = now()->timestamp;
        $kept = [];
        $found = null;
        foreach ($grants as $grant) {
            if (! is_array($grant) || ($grant['expires_at'] ?? 0) < $now) {
                continue;
            }
            $kept[] = $grant;
            if ($found === null
                && (int) ($grant['product_id'] ?? 0) === $productId
                && (int) ($grant['variant_id'] ?? 0) === (int) ($variantId ?? 0)) {
                $found = $grant;
            }
        }
        session([self::SESSION_KEY => $kept]);

        return $found;
    }
}
