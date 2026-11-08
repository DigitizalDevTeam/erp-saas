<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal Consumption invoice series (option B, Riz 2026-11-03;
 * expense account 611 confirmed 2026-11-08).
 *
 * Marks invoices created from internal-consumption POS orders so
 * they can be numbered in their own #INT series (starting at 1)
 * while sales numbering excludes them. Old internal invoices
 * created before this change keep document_type NULL — they stay
 * in the sales series exactly as they were (no data migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('document_type', 20)->nullable()->after('pos_id');
            $table->index(['created_by', 'document_type'], 'inv_creator_dtype_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('inv_creator_dtype_idx');
            $table->dropColumn('document_type');
        });
    }
};
