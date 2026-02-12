<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add indexes to speed up POS search-products (lean list + category filter).
     */
    public function up(): void
    {
        $table = 'product_services';
        if (!Schema::hasTable($table)) {
            return;
        }
        try {
            DB::statement("CREATE INDEX product_services_pos_list_idx ON {$table} (created_by, type, id DESC)");
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
        try {
            DB::statement("CREATE INDEX product_services_pos_cat_idx ON {$table} (type, category_id, created_by)");
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        $table = 'product_services';
        if (!Schema::hasTable($table)) {
            return;
        }
        DB::statement("DROP INDEX IF EXISTS product_services_pos_list_idx ON {$table}");
        DB::statement("DROP INDEX IF EXISTS product_services_pos_cat_idx ON {$table}");
    }
};
