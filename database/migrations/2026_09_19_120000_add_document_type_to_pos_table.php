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
        if (! Schema::hasColumn('pos', 'document_type')) {
            Schema::table('pos', function (Blueprint $table) {
                $table->string('document_type', 32)->default('cash_sale')->after('payment_method');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('pos', 'document_type')) {
            Schema::table('pos', function (Blueprint $table) {
                $table->dropColumn('document_type');
            });
        }
    }
};
