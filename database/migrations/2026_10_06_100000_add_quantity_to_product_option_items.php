<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_option_items') && ! Schema::hasColumn('product_option_items', 'quantity')) {
            Schema::table('product_option_items', function (Blueprint $table) {
                $table->decimal('quantity', 15, 3)->default(1)->after('is_required');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_option_items') && Schema::hasColumn('product_option_items', 'quantity')) {
            Schema::table('product_option_items', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }
};
