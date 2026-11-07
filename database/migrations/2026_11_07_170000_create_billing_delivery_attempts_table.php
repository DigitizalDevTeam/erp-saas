<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 2 — billing delivery attempts.
 *
 * One row per issued contract invoice: the email delivery history
 * for that invoice (plan §5, §6 delivery state machine). A failed
 * send retries the SAME invoice and the SAME stored PDF — never a
 * new invoice, never a new number (AC08). The PDF bytes are stored
 * once at issuance under storage/app/billing-invoice-pdfs/ and
 * reused for every retry, so the attachment is literally identical.
 *
 * Retry backoff: 5 min → 30 min → 2 hours, then final (4 sends
 * max: the first attempt + 3 retries). A manual resend ignores
 * next_retry_at but still counts as an attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_delivery_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('channel', 20)->default('email');
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->string('status', 20)->default('queued');
            $table->integer('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id'], 'bda_invoice_uq');
            $table->index(['contract_id'], 'bda_contract_idx');
            $table->index(['created_by', 'status', 'next_retry_at'], 'bda_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_delivery_attempts');
    }
};
