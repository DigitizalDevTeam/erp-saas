<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tell manual journals apart from system-posted ones.
 *
 * journal_entries has no manual/system marker: the manual Journal
 * Entry screen creates PENDING rows and the approve step flips them
 * to APPROVED — the same status the JournalEntryPoster writes for
 * system postings (bills, treasury, fiscal closing, ...). Without a
 * marker, the management P&L could not count a manual journal
 * without also counting the system posting of the same cost.
 *
 * source: 'manual' (JournalEntryController@store) or 'system'
 * (JournalEntryPoster::post). Rows written before this migration
 * stay NULL and are never counted by the management reports — the
 * safe direction (an old manual journal is left out rather than
 * risking a double-count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('journal_id');
            $table->index(['created_by', 'source', 'status'], 'je_creator_source_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_creator_source_status_idx');
            $table->dropColumn('source');
        });
    }
};
