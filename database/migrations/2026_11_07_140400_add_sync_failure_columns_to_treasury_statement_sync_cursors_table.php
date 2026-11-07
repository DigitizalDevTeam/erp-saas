<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — sync-failure recording on statement sync
 * cursors (plan §8 alerts).
 *
 * A failed API sync records its error on the cursor it was
 * syncing; the next successful import clears it. The alert
 * service raises a sync_failure alert while a recent failure
 * stands, and resolves it once the error is cleared. Additive
 * only — no existing column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_statement_sync_cursors', function (Blueprint $table) {
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('treasury_statement_sync_cursors', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_error_at']);
        });
    }
};
