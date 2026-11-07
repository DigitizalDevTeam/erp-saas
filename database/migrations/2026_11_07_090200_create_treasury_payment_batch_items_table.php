<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 3 — batch items (plan §5, §6, CA07).
 *
 * One row per instruction in a batch: the payment request, the
 * amount to execute for it (its remaining amount by default), the
 * outcome of its own execution (pending / paid / failed / skipped),
 * the attempt that settled it, and the failure reason when it
 * failed — the failed item stays for review, it is not dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_payment_batch_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('batch_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            // pending / paid / failed / skipped
            $table->string('state', 20)->default('pending');
            $table->bigInteger('attempt_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['batch_id', 'payment_request_id'], 'tpbi_batch_request_uq');
            $table->index(['batch_id'], 'tpbi_batch_idx');
            $table->index(['payment_request_id'], 'tpbi_request_idx');
            $table->index(['attempt_id'], 'tpbi_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_payment_batch_items');
    }
};
