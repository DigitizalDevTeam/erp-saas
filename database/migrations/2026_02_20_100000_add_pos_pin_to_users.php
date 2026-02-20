<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restaurant POS: simple PIN for waiter/cashier login on shared device.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pos_pin', 10)->nullable()->after('pos_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pos_pin');
        });
    }
};
