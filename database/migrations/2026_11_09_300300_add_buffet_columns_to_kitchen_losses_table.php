<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Traceability: a buffet leftover / waste loss knows which
        // event and line it came from (the event show page lists its
        // linked losses). Nullable and additive — existing losses are
        // untouched.
        Schema::table('kitchen_losses', function (Blueprint $table) {
            $table->unsignedBigInteger('kitchen_buffet_event_id')->nullable()->after('kitchen_production_batch_id');
            $table->unsignedBigInteger('kitchen_buffet_event_line_id')->nullable()->after('kitchen_buffet_event_id');
            $table->index(['kitchen_buffet_event_id'], 'kl_buffet_event_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_losses', function (Blueprint $table) {
            $table->dropIndex('kl_buffet_event_idx');
            $table->dropColumn(['kitchen_buffet_event_id', 'kitchen_buffet_event_line_id']);
        });
    }
};
