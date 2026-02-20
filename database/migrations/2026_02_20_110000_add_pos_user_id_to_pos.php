<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restaurant POS: user who took the order (PIN user / waiter or cashier).
     */
    public function up(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_user_id')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn('pos_user_id');
        });
    }
};
