<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_service_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('type');
            $table->index(['created_by', 'type', 'parent_id'], 'idx_psc_creator_type_parent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_service_categories', function (Blueprint $table) {
            $table->dropIndex('idx_psc_creator_type_parent');
            $table->dropColumn('parent_id');
        });
    }
};
