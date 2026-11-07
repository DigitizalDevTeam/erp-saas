<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GL dimensions (Cost Centre Phase 4, plan §5/§8, decision D8).
        //
        // One row per (posted journal line, cost centre): which cost
        // centres a posted line's money belongs to, and how much each
        // gets. A line is either a JournalItem (manual journals) or a
        // TransactionLines row (documents posted directly, e.g. bills
        // and expenses) — D8 decided BOTH, through this one table with
        // a line_type, because this app posts money through both paths
        // and a dimension that only covers one of them misses half the
        // expenses.
        //
        // Dimensions sit BESIDE the money: these rows never create,
        // change or delete a journal line, a bank movement or a
        // balance. The journal line stays the source of truth; the
        // dimension row never changes the debit or credit.
        //
        // Amounts are SIGNED: a debit line carries positive amounts, a
        // credit line carries negative amounts, so dimension totals
        // reconcile to the net GL amounts (debit − credit) instead of
        // to a different total.
        //
        // Rows are never edited in place: re-tagging a line supersedes
        // the old set; a reversal is a NEW row with a negated amount
        // linked to the original via reversal_of_id, and the original
        // row stays readable so the audit trail is complete.
        Schema::create('journal_line_dimensions', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            // journal_item | transaction_line (plan decision D8: both).
            $table->string('line_type', 20);
            // The JournalItem id, or the TransactionLines id.
            $table->unsignedBigInteger('line_id');
            // The JournalEntry id when the line belongs to a manual
            // journal (null for document lines). Denormalised so a
            // reversal can find the journal's dimensions at once.
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            // Second dimension (plan §5): independent of the centre.
            // Left empty when a transaction has no project (D6).
            $table->unsignedBigInteger('project_id')->nullable();
            // Future Fixed Assets integration (plan §5): stays null in Phase 4.
            $table->unsignedBigInteger('asset_id')->nullable();
            // The actual rounded amount for this centre — persisted,
            // never recomputed. Rows for one line sum to the line's
            // net amount (debit − credit), signed.
            $table->decimal('allocated_amount', 18, 2);
            // The split % that produced the amount, when the row came
            // from a percentage split (null for fixed-amount splits).
            $table->decimal('percentage', 9, 6)->nullable();
            // Base currency of the company (D7: currency stored from
            // day one; the app is single-currency per company, so no
            // conversion happens here).
            $table->string('currency_code', 10)->nullable();
            // manual | inherited | reversal — how the row was produced.
            $table->string('source', 20)->default('manual');
            // active | superseded — re-tagging supersedes the old set
            // (reverse-and-repost pattern: posted rows are never edited
            // in place, the audit event keeps the before/after).
            $table->string('status', 20)->default('active');
            // The dimension row this row reverses (reversal rows only).
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['line_type', 'line_id', 'status'], 'jld_line_status_idx');
            $table->index(['created_by', 'cost_centre_id'], 'jld_company_centre_idx');
            $table->index(['journal_id'], 'jld_journal_idx');
            $table->index(['cost_centre_id'], 'jld_centre_idx');
            $table->index(['reversal_of_id'], 'jld_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_line_dimensions');
    }
};
