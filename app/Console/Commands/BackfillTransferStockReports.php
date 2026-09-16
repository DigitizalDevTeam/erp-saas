<?php

namespace App\Console\Commands;

use App\Models\StockReport;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class BackfillTransferStockReports extends Command
{
    protected $signature = 'stock:backfill-transfer-ledger
                            {--company= : Limit to this company (created_by) id}
                            {--dry-run : Show what would be written without saving}';

    protected $description = 'Create stock_reports ledger entries for warehouse transfers missing movement history';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = WarehouseTransfer::query()
            ->when($companyId, fn ($q) => $q->where('created_by', $companyId))
            ->orderBy('id');

        $written = 0;
        $skipped = 0;

        foreach ($query->cursor() as $transfer) {
            $exists = StockReport::where('created_by', $transfer->created_by)
                ->where('product_id', $transfer->product_id)
                ->where('type_id', $transfer->id)
                ->whereIn('type', ['transfer_in', 'transfer_out'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $fromName = warehouse::find($transfer->from_warehouse)->name ?? ('#' . $transfer->from_warehouse);
            $toName = warehouse::find($transfer->to_warehouse)->name ?? ('#' . $transfer->to_warehouse);
            $route = $fromName . ' → ' . $toName;

            $this->line("Transfer #{$transfer->id}: product #{$transfer->product_id}, qty {$transfer->quantity}, {$route}");

            if ($dryRun) {
                $written++;

                continue;
            }

            $user = User::find($transfer->created_by);
            if ($user) {
                Auth::login($user);
            }

            Utility::addProductStock(
                $transfer->product_id,
                $transfer->quantity,
                'transfer_out',
                __('Transfer out') . ': ' . $route,
                $transfer->id
            );
            Utility::addProductStock(
                $transfer->product_id,
                $transfer->quantity,
                'transfer_in',
                __('Transfer in') . ': ' . $route,
                $transfer->id
            );

            $written++;
        }

        $this->info(($dryRun ? 'Would write' : 'Written') . ": {$written} transfer ledger(s); skipped (already present): {$skipped}.");

        return self::SUCCESS;
    }
}
