<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4: Payment types Cash/Card/Credit; for Credit store terms and customer.
     */
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('cash')->after('branch_id'); // cash | card | credit
            $table->string('credit_terms', 20)->nullable()->after('payment_method');   // e.g. 15, 30 (days)
            $table->unsignedBigInteger('customer_id')->nullable()->after('credit_terms');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'credit_terms', 'customer_id']);
        });
    }
};
