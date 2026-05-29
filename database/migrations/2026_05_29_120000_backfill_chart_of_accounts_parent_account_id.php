<?php

use App\Support\ChartOfAccountHierarchy;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccountHierarchy::backfillLegacyParentLinks();
    }

    public function down(): void
    {
        // Non-destructive data backfill; no rollback.
    }
};
