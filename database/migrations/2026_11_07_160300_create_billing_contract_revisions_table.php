<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing contract revisions
 * (plan §5, §6 amendment flow).
 *
 * An amendment is a new revision: author, reason, effective date
 * and a JSON snapshot of the lines + schedule + terms at that
 * revision. Issued invoices keep their old revision; a new
 * revision never rewrites an issued invoice — it applies to
 * unissued periods from the effective date only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_contract_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('revision_number');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['contract_id', 'revision_number'], 'bcr_contract_rev_uq');
            $table->index(['contract_id'], 'bcr_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_contract_revisions');
    }
};
