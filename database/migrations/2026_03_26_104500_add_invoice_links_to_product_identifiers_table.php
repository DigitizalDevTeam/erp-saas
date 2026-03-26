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
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable()->after('pos_product_id');
            $table->unsignedBigInteger('invoice_product_id')->nullable()->after('invoice_id');

            $table->index(['created_by', 'invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'invoice_id']);
            $table->dropColumn(['invoice_id', 'invoice_product_id']);
        });
    }
};
