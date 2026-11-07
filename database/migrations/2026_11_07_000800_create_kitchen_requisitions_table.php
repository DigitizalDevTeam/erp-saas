<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_requisitions', function (Blueprint $table) {
            $table->id();
            // Per-company sequential number, allocated at creation
            // (RQ-{YEAR}-{SEQ}); the warehouse refers to the request by it.
            $table->string('requisition_number', 30);
            // A requisition is raised from a plan or an order (or neither,
            // for a manual request). It is NEVER a purchase order: it asks
            // for stock that is already in a warehouse.
            $table->unsignedBigInteger('kitchen_production_plan_id')->nullable();
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            // Stock moves FROM the source warehouse TO the kitchen warehouse.
            $table->unsignedBigInteger('source_warehouse_id');
            $table->unsignedBigInteger('kitchen_warehouse_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            // requested | approved | issued | partially_issued | rejected
            // (plan §6).
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'requisition_number'], 'kr_created_by_number_unique');
            $table->index(['created_by', 'status'], 'kr_created_by_status_idx');
            $table->index(['kitchen_production_plan_id'], 'kr_plan_idx');
            $table->index(['kitchen_production_order_id'], 'kr_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_requisitions');
    }
};
