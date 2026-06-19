<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_option_items')) {
            return;
        }

        Schema::table('product_option_items', function (Blueprint $table) {
            if (! Schema::hasColumn('product_option_items', 'option_group')) {
                $table->string('option_group', 100)->nullable()->after('option_product_id');
            }
            if (! Schema::hasColumn('product_option_items', 'is_required')) {
                $table->boolean('is_required')->default(false)->after('option_group');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_option_items')) {
            return;
        }

        Schema::table('product_option_items', function (Blueprint $table) {
            if (Schema::hasColumn('product_option_items', 'is_required')) {
                $table->dropColumn('is_required');
            }
            if (Schema::hasColumn('product_option_items', 'option_group')) {
                $table->dropColumn('option_group');
            }
        });
    }
};
