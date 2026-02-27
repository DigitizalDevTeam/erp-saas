<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link POS "pay balance" (invoice) payments to shift so they count in shift closing and EOD.
     */
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_shift_id')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('pos_shift_id');
        });
    }
};
