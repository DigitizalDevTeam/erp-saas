<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Product type "ingredient" is stored in product_services.type (string).
     * Hidden from POS; used as recipe/BOM stock items.
     */
    public function up(): void
    {
        // No schema change required — type column already accepts custom values.
    }

    public function down(): void
    {
        DB::table('product_services')->where('type', 'ingredient')->update(['type' => 'product']);
    }
};
