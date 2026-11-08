<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost Centre Phase 7 — production: when a kitchen production
     * order completes, the consumed-ingredient cost is attributed to
     * the order's cost centre. Attribution beside the money only —
     * stock deduction and journals are untouched.
     */
    public function up(): void
    {
        Schema::create('kitchen_production_cost_dimensions', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('kitchen_production_order_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('production_date')->nullable();
            $table->timestamps();

            $table->unique('kitchen_production_order_id', 'kpcd_order_uq');
            $table->index(['created_by', 'cost_centre_id'], 'kpcd_centre_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_cost_dimensions');
    }
};
