<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit Notes Phase 1 (2026-11-18) — justification + disposition.
 *
 * - `disposition`: what happens to the money — 'refund' (pay it back,
 *   handled by Treasury in Phase 2) or 'credit' (keep it as customer
 *   credit). Nullable: notes created before this change and notes
 *   created by the void flow carry no disposition.
 * - `attachment`: optional supporting document uploaded with the note
 *   (customer request, approval, photos). Stored file name only; the
 *   file itself lives under storage/app/uploads/credit-notes, the same
 *   pattern as invoice payment receipts (uploads/payment).
 */
class AddDispositionAttachmentToCreditNotes extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_notes', 'disposition')) {
                $table->string('disposition', 20)->nullable()->after('description');
            }
            if (! Schema::hasColumn('credit_notes', 'attachment')) {
                $table->string('attachment')->nullable()->after('disposition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            if (Schema::hasColumn('credit_notes', 'attachment')) {
                $table->dropColumn('attachment');
            }
            if (Schema::hasColumn('credit_notes', 'disposition')) {
                $table->dropColumn('disposition');
            }
        });
    }
}
