<?php

use App\Models\IrpsWithholdingRow;
use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedIrpsWithholdingRowsAndPermission extends Migration
{
    /**
     * Seed the official IRPS withholding table for every company that
     * runs payroll. The table was empty for all companies, so every
     * payslip computed a 0.00 IRPS and the company bore the workers'
     * tax. Also creates the permission that gates the rates screen.
     */
    public function up(): void
    {
        if (Schema::hasTable('irps_withholding_rows')) {
            $createdByIds = [1];

            if (Schema::hasTable('employees')) {
                foreach (DB::table('employees')->distinct()->pluck('created_by')->all() as $id) {
                    $createdByIds[] = (int) $id;
                }
            }

            foreach (array_values(array_unique($createdByIds)) as $createdBy) {
                IrpsWithholdingRow::seedMoz2014ForCompany($createdBy);
            }
        }

        AdminRolePermissionBackfill::grantIrpsWithholdingPermission();
    }

    public function down(): void
    {
        // Rates and permission are data, not schema: leave them in place.
    }
}
