<?php

namespace App\Console\Commands;

use App\Models\StockReport;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CleanupOrphanWarehouseStock extends Command
{
    protected $signature = 'stock:cleanup-orphan-products
                            {--company= : Limit to this company (created_by) id}
                            {--dry-run : Show what would be cleaned without writing}';

    protected $description = 'Reverse orphan warehouse transfers and clear warehouse stock for hard-deleted products';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Dry run — no changes will be written.' : 'Cleaning orphan warehouse stock for missing products...');

        $orphanProductIds = WarehouseProduct::query()
            ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_services')
                    ->whereColumn('product_services.id', 'warehouse_products.product_id');
            })
            ->distinct()
            ->pluck('product_id')
            ->merge(
                WarehouseTransfer::query()
                    ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('product_services')
                            ->whereColumn('product_services.id', 'warehouse_transfers.product_id');
                    })
                    ->distinct()
                    ->pluck('product_id')
            )
            ->unique()
            ->values();

        if ($orphanProductIds->isEmpty()) {
            $this->info('No orphan product stock or transfers found.');

            return self::SUCCESS;
        }

        $this->info('Orphan product ids: ' . $orphanProductIds->implode(', '));

        $reversed = 0;
        $cleared = 0;
        $audits = 0;

        foreach ($orphanProductIds as $productId) {
            $productId = (int) $productId;
            $label = __('Product deleted') . ' (#' . $productId . ')';

            $transfers = WarehouseTransfer::query()
                ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
                ->where('product_id', $productId)
                ->get();

            $rows = WarehouseProduct::query()
                ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
                ->where('product_id', $productId)
                ->get();

            $creatorForAudit = $companyId
                ?: (int) ($transfers->first()->created_by ?? $rows->first()->created_by ?? 0);

            foreach ($transfers as $transfer) {
                $this->line("  Reverse transfer #{$transfer->id}: WH {$transfer->to_warehouse} → {$transfer->from_warehouse}, qty {$transfer->quantity}");
                if (! $dryRun) {
                    $this->actingAsCreator((int) $transfer->created_by);
                    Utility::warehouse_transfer_qty(
                        $transfer->to_warehouse,
                        $transfer->from_warehouse,
                        $transfer->product_id,
                        $transfer->quantity,
                        'delete'
                    );
                    $this->writeAudit(
                        $productId,
                        (float) $transfer->quantity,
                        $label . ' — ' . __('Transfer reversed') . ' #' . $transfer->id,
                        (int) $transfer->id,
                        (int) $transfer->created_by
                    );
                    $transfer->delete();
                    $audits++;
                }
                $reversed++;
            }

            // Re-fetch rows after reverse may have changed quantities / locations
            $rows = WarehouseProduct::query()
                ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
                ->where('product_id', $productId)
                ->get();

            foreach ($rows as $row) {
                $qty = (float) $row->quantity;
                $this->line("  Clear warehouse_products #{$row->id}: WH {$row->warehouse_id}, qty {$qty}");
                if (! $dryRun) {
                    $this->actingAsCreator((int) $row->created_by);
                    if ($qty > 0) {
                        $this->writeAudit(
                            $productId,
                            $qty,
                            $label . ' — ' . __('Warehouse stock cleared') . ' (WH #' . $row->warehouse_id . ')',
                            (int) $row->warehouse_id,
                            (int) $row->created_by
                        );
                        $audits++;
                    }
                    $row->delete();
                }
                $cleared++;
            }

            if (! $dryRun && $creatorForAudit > 0) {
                $this->actingAsCreator($creatorForAudit);
                $this->writeAudit($productId, 0, $label, $productId, $creatorForAudit);
                $audits++;
            }
        }

        $this->info("Transfers reversed: {$reversed}");
        $this->info("Warehouse rows cleared: {$cleared}");
        $this->info("Audit stock_reports written: {$audits}");

        return self::SUCCESS;
    }

    protected function actingAsCreator(int $creatorId): void
    {
        $user = \App\Models\User::find($creatorId);
        if ($user) {
            Auth::login($user);
        }
    }

    protected function writeAudit(int $productId, float $quantity, string $description, int $typeId, int $createdBy): void
    {
        $stocks = new StockReport();
        $stocks->product_id = $productId;
        $stocks->quantity = $quantity;
        $stocks->type = 'product_delete';
        $stocks->type_id = $typeId;
        $stocks->description = $description;
        $stocks->created_by = $createdBy;
        $stocks->save();
    }
}
