<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_production_plans', function (Blueprint $table) {
            $table->id();
            // Per-company sequential number, allocated at creation
            // (PL-{YEAR}-{SEQ}); the chef refers to "today's breakfast
            // plan" by it from the moment the plan exists.
            $table->string('plan_number', 30);
            $table->date('plan_date');
            // breakfast | lunch | dinner
            $table->string('meal_service', 20);
            $table->unsignedBigInteger('branch_id')->nullable();
            // The kitchen warehouse the plan produces for (decision D6).
            $table->unsignedBigInteger('warehouse_id');
            // hot_kitchen | pastry | cold_kitchen (same sections as orders).
            $table->string('section', 30)->nullable();
            // draft | pending_approval | approved | in_production |
            // completed | rejected (plan §6).
            $table->string('status', 20)->default('draft');
            // Next approval step while pending: head_chef | fb_manager |
            // general_manager. Null once the chain is done or not started.
            $table->string('approval_step', 20)->nullable();
            // Occupancy snapshot taken when the plan is created (and
            // refreshed while draft): the counts AND the reservations
            // behind them, so the chef can see exactly why a number is
            // what it is (plan §11.4).
            $table->json('occupancy_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('head_chef_approved_by')->nullable();
            $table->timestamp('head_chef_approved_at')->nullable();
            $table->unsignedBigInteger('fb_manager_approved_by')->nullable();
            $table->timestamp('fb_manager_approved_at')->nullable();
            $table->unsignedBigInteger('gm_approved_by')->nullable();
            $table->timestamp('gm_approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'plan_number'], 'kpp_created_by_number_unique');
            $table->index(['created_by', 'plan_date'], 'kpp_created_by_date_idx');
            $table->index(['created_by', 'status'], 'kpp_created_by_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_plans');
    }
};
