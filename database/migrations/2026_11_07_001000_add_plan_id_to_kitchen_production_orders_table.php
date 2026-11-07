<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_production_orders', function (Blueprint $table) {
            // The plan this order was generated from (Phase 3). Null for
            // orders created directly from the Orders screen.
            $table->unsignedBigInteger('kitchen_production_plan_id')->nullable()->after('kitchen_recipe_id');
            $table->index(['kitchen_production_plan_id'], 'kpo_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_production_orders', function (Blueprint $table) {
            $table->dropIndex('kpo_plan_idx');
            $table->dropColumn('kitchen_production_plan_id');
        });
    }
};
