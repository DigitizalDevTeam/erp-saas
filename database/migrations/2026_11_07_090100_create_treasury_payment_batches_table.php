<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 3 — payment batches (plan §5, §6, CA07).
 *
 * A batch groups several approved payment requests under one
 * reference, one state and one outcome per instruction. A batch is
 * never all-or-nothing on the money: each instruction settles or
 * fails on its own, and a failed item stays in the batch for review
 * while the successful items stay settled.
 *
 * The reference is allocated only when the batch leaves Draft
 * (first execution), like the operation references in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_payment_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('provider', 50)->nullable();
            // draft / processing / partially_paid / paid / failed / cancelled
            $table->string('state', 20)->default('draft');
            $table->text('note')->nullable();
            $table->bigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'reference'], 'tpb_company_ref_uq');
            $table->index(['created_by', 'state'], 'tpb_company_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_payment_batches');
    }
};
