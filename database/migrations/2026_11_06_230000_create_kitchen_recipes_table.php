<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->string('name');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            // draft | pending_approval | approved | archived
            $table->string('status', 20)->default('draft');
            $table->decimal('expected_yield_quantity', 12, 4)->nullable();
            $table->unsignedBigInteger('expected_yield_unit_id')->nullable();
            $table->decimal('standard_portion_quantity', 12, 4)->nullable();
            $table->unsignedBigInteger('standard_portion_unit_id')->nullable();
            $table->unsignedInteger('preparation_time_minutes')->nullable();
            $table->text('equipment')->nullable();
            $table->text('instructions')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->text('allergens')->nullable();
            $table->string('photo')->nullable();
            // Cleaning / preparation loss % (spec §2). Effective recipe cost
            // is grossed up by this loss in KitchenRecipeCostService.
            $table->decimal('loss_percent', 5, 2)->default(0);
            // Plain field for now: the Cost Centre module does not exist yet.
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->integer('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();

            // Versions of one recipe share the code, so the code is
            // unique per company AND version, not per company alone.
            $table->unique(['created_by', 'code', 'version']);
            $table->unique(['created_by', 'product_id', 'version']);
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_recipes');
    }
};
