<?php

use App\Models\ChartOfAccountType;
use App\Services\Accounting\ChartOfAccountCsvImportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fix chart of accounts hidden on index when duplicate type names exist (re-import side effect).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_account_types')) {
            return;
        }

        $service = app(ChartOfAccountCsvImportService::class);

        foreach (ChartOfAccountType::query()->distinct()->pluck('created_by') as $creatorId) {
            $creatorId = (int) $creatorId;
            if ($creatorId <= 0) {
                continue;
            }

            $service->dedupeDuplicateAccountTypes($creatorId);
        }
    }

    public function down(): void
    {
        // Data repair — no rollback.
    }
};
