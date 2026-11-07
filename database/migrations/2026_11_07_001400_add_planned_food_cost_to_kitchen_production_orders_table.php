<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_production_orders', function (Blueprint $table) {
            // Planned food cost % / band, snapshotted when the order is
            // created (or re-planned) — the "plan" side of the
            // expected-vs-actual comparison (plan §7). Null for orders
            // created before this phase, which have no planned band to
            // compare against.
            $table->decimal('planned_food_cost_percent', 5, 2)->nullable()->after('food_cost_band');
            $table->string('planned_food_cost_band', 20)->nullable()->after('planned_food_cost_percent');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_production_orders', function (Blueprint $table) {
            $table->dropColumn(['planned_food_cost_percent', 'planned_food_cost_band']);
        });
    }
};
