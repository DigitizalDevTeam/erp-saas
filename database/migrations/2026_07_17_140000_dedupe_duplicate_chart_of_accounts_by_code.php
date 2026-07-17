<?php

use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountCsvImportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Remove duplicate chart_of_accounts rows (same code) left after type dedupe / re-import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $service = app(ChartOfAccountCsvImportService::class);

        foreach (ChartOfAccount::query()->distinct()->pluck('created_by') as $creatorId) {
            $creatorId = (int) $creatorId;
            if ($creatorId <= 0) {
                continue;
            }

            $service->dedupeDuplicateAccountsByCode($creatorId);
        }
    }

    public function down(): void
    {
        // Data repair — no rollback.
    }
};
