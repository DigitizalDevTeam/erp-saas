<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost Centre Phase 7 — HR payroll: a payslip's gross expense can
     * be split across cost centres (percentages summing 100%), so the
     * payroll cost lands in each centre's actuals. Dimensions beside
     * the money only — the posted journal amounts never change.
     */
    public function up(): void
    {
        Schema::create('payslip_cost_centre_splits', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('payslip_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('percentage', 9, 6);
            $table->decimal('amount', 20, 4)->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['payslip_id', 'cost_centre_id', 'project_id'], 'pcss_payslip_centre_uq');
            $table->index('payslip_id', 'pcss_payslip_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_cost_centre_splits');
    }
};
