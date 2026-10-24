<?php

namespace App\Services;

use App\Models\LowStockNotification;
use App\Models\LowStockNotificationRead;
use App\Models\ProductService;
use App\Models\ProductWarehouseStockAlert;
use App\Models\User;
use App\Models\WarehouseProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header-bell low-stock alerts, one per product + warehouse.
 *
 * Trigger: any stock change that runs through Utility::warehouse_quantity,
 * Utility::addWarehouseStock, Utility::warehouse_transfer_qty, or the
 * per-warehouse threshold popup save. The check recomputes the warehouse
 * total from warehouse_products, so it is correct no matter which of
 * those paths changed the stock.
 *
 * Low level, same rule as the Stock Status popup and the POS shift
 * Minimum Stock Alert: per-warehouse Low first, then the product's Low,
 * then the POS low-stock default (10). A Low of 0 or less means "no
 * alert" (it would otherwise fire for every zero-stock product).
 *
 * Deduplication: at most one UNRESOLVED alert per product + warehouse.
 * While stock stays low, further sales only refresh the quantity
 * snapshot on the existing alert. When stock recovers above Low, the
 * alert resolves; a later fall creates a fresh alert.
 *
 * Read status is per user: one user marking an alert read does not
 * mark it read for anyone else.
 */
class LowStockNotificationService
{
    /**
     * Deliberately NOT memoized: the schema is per-request in production,
     * but test processes reuse one process across different schemas, and
     * a stale memoized answer would silently disable the bell.
     */
    private static function tablesReady(): bool
    {
        return Schema::hasTable('low_stock_notifications')
            && Schema::hasTable('low_stock_notification_reads');
    }

    /**
     * Re-evaluate one product in one warehouse after a stock change.
     * Safe to call when the tables are not migrated yet (no-op).
     */
    public static function checkProductWarehouse(int $productId, int $warehouseId, int $companyId): void
    {
        if ($productId <= 0 || $warehouseId <= 0 || $companyId <= 0) {
            return;
        }
        if (! self::tablesReady()) {
            return;
        }

        $product = ProductService::where('id', $productId)
            ->where('created_by', $companyId)
            ->first();
        if (! $product || ! ($product->affect_stock ?? true)) {
            return;
        }
        // Kits hold no stock of their own and services move no stock.
        if (! in_array($product->type, ['product', 'ingredient'], true)) {
            return;
        }

        $qty = (float) WarehouseProduct::where('created_by', $companyId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');

        $alert = null;
        if (Schema::hasTable('product_warehouse_stock_alerts')) {
            $alert = ProductWarehouseStockAlert::where('created_by', $companyId)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();
        }
        $low = $alert && $alert->low_stock_threshold !== null
            ? (int) $alert->low_stock_threshold
            : ($product->low_stock_threshold !== null ? (int) $product->low_stock_threshold : self::defaultLow($companyId));

        $existing = LowStockNotification::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('resolved_at')
            ->first();

        if ($low > 0 && $qty <= $low) {
            if ($existing) {
                $existing->update(['quantity' => $qty, 'low_stock_threshold' => $low]);
            } else {
                LowStockNotification::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $qty,
                    'low_stock_threshold' => $low,
                ]);
            }
        } elseif ($existing) {
            $existing->update(['resolved_at' => now()]);
        }
    }

    public static function defaultLow(int $companyId): int
    {
        if (Schema::hasTable('settings')) {
            $value = DB::table('settings')
                ->where('created_by', $companyId)
                ->where('name', 'pos_low_stock_threshold')
                ->value('value');
            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        return 10;
    }

    public static function unreadCountForUser(User $user): int
    {
        if (! self::tablesReady()) {
            return 0;
        }

        return self::visibleQuery($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    /**
     * Latest alerts (unresolved first, then recently resolved), each
     * with an is_read flag for this user.
     *
     * @return Collection<int, LowStockNotification>
     */
    public static function recentForUser(User $user, int $limit = 10): Collection
    {
        if (! self::tablesReady()) {
            return collect();
        }

        $items = self::visibleQuery($user)
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderByRaw('resolved_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $readIds = LowStockNotificationRead::where('user_id', $user->id)
            ->whereIn('notification_id', $items->pluck('id')->all())
            ->pluck('notification_id')
            ->all();
        foreach ($items as $item) {
            $item->is_read = in_array($item->id, $readIds, true);
        }

        return $items;
    }

    public static function markRead(int $notificationId, User $user): bool
    {
        if (! self::tablesReady()) {
            return false;
        }
        $notification = LowStockNotification::where('id', $notificationId)
            ->where('company_id', (int) $user->creatorId())
            ->first();
        if (! $notification) {
            return false;
        }

        LowStockNotificationRead::updateOrCreate(
            ['notification_id' => $notification->id, 'user_id' => $user->id],
            ['read_at' => now()]
        );

        return true;
    }

    public static function markAllRead(User $user): int
    {
        if (! self::tablesReady()) {
            return 0;
        }

        $ids = self::visibleQuery($user)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id')
            ->all();

        $now = now();
        foreach ($ids as $id) {
            LowStockNotificationRead::updateOrCreate(
                ['notification_id' => $id, 'user_id' => $user->id],
                ['read_at' => $now]
            );
        }

        return count($ids);
    }

    private static function visibleQuery(User $user)
    {
        return LowStockNotification::where('company_id', (int) $user->creatorId());
    }
}
