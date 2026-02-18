<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Phase 4: Cancel order – mark Pos as cancelled. */
    public function up(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('client_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
