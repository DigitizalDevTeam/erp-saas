<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_production_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_production_plan_id');
            $table->unsignedBigInteger('kitchen_recipe_id');
            // The recipe version this line is locked to (like orders).
            $table->unsignedInteger('recipe_version')->default(1);
            // Planned quantity in portions or kg, with its unit.
            $table->decimal('planned_quantity', 12, 4);
            $table->unsignedBigInteger('planned_unit_id')->nullable();
            // What the quantity is based on: occupancy | sales_history |
            // event | minimum_stock | special_order | manual. The basis is
            // always recorded so the plan can be audited later.
            $table->string('source', 30)->default('manual');
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['kitchen_production_plan_id'], 'kppl_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_plan_lines');
    }
};
