<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_recipes', function (Blueprint $table) {
            $table->unsignedBigInteger('kitchen_recipe_id')->nullable()->after('product_id');
            $table->unsignedInteger('version')->default(1)->after('kitchen_recipe_id');
            $table->decimal('loss_percent', 5, 2)->default(0)->after('quantity');
            $table->index(['kitchen_recipe_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('product_recipes', function (Blueprint $table) {
            $table->dropIndex(['kitchen_recipe_id', 'version']);
            $table->dropColumn(['kitchen_recipe_id', 'version', 'loss_percent']);
        });
    }
};
