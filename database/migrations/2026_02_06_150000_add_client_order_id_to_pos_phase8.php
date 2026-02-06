<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8: Idempotent offline sync - client sends client_order_id to avoid duplicates.
     */
    public function up(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->string('client_order_id', 100)->nullable()->after('created_by');
        });
        Schema::table('pos', function (Blueprint $table) {
            $table->index(['created_by', 'client_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'client_order_id']);
        });
        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn('client_order_id');
        });
    }
};
