<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-category "Show on POS" toggle. Default ON: existing
        // categories keep appearing in the POS category buttons.
        if (Schema::hasTable('product_service_categories') && ! Schema::hasColumn('product_service_categories', 'show_on_pos')) {
            Schema::table('product_service_categories', function (Blueprint $table) {
                $table->boolean('show_on_pos')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_service_categories') && Schema::hasColumn('product_service_categories', 'show_on_pos')) {
            Schema::table('product_service_categories', function (Blueprint $table) {
                $table->dropColumn('show_on_pos');
            });
        }
    }
};
