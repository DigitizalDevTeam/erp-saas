<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1: Order line status & production flow.
     * Add status lifecycle (pending → in_preparation → ready → served | cancelled) and print_zone for kitchen/bar.
     */
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->after('description'); // pending, in_preparation, ready, served, cancelled
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->timestamp('ready_at')->nullable()->after('sent_at');
            $table->string('print_zone', 20)->nullable()->after('ready_at'); // kitchen, bar, both - for production screen filter
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn(['status', 'sent_at', 'ready_at', 'print_zone']);
        });
    }
};
