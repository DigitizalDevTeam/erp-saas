<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->string('transaction_id', 100)->nullable()->after('credit_terms');
            $table->decimal('change_amount', 15, 2)->nullable()->default(0)->after('amount');
            $table->text('notes')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropColumn(['transaction_id', 'change_amount', 'notes']);
        });
    }
};
