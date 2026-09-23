<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Restaurant POS: waiter/user assigned when opening a table. */
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_tables', 'waiter_user_id')) {
                $table->unsignedBigInteger('waiter_user_id')->nullable()->after('customer_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            if (Schema::hasColumn('pos_tables', 'waiter_user_id')) {
                $table->dropColumn('waiter_user_id');
            }
        });
    }
};
