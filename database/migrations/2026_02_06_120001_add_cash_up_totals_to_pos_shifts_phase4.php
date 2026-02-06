<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4: Cash-up totals per payment type and difference.
     */
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->decimal('cash_total', 16, 2)->nullable()->after('sales_total');
            $table->decimal('card_total', 16, 2)->nullable()->after('cash_total');
            $table->decimal('credit_total', 16, 2)->nullable()->after('card_total');
            $table->decimal('difference', 16, 2)->nullable()->after('closing_balance');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn(['cash_total', 'card_total', 'credit_total', 'difference']);
        });
    }
};
