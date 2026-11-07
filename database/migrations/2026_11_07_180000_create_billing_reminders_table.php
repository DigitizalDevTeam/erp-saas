<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 3 — billing reminders.
 *
 * One row per overdue contract invoice: the reminder schedule
 * for that invoice. The cadence is per company (settings keys
 * billing_reminder_first_days / billing_reminder_interval_days /
 * billing_reminder_max, defaults 3 / 7 / 3): the first reminder
 * goes out first_days after the due date, then every
 * interval_days, at most max reminders. A reminder is an email
 * sent through the same CustomerInvoiceSend mailable as the
 * invoice itself, with the SAME stored PDF (AC08 pattern) — a
 * failed reminder retries the same invoice/PDF, never a new
 * invoice. attempt_count tracks send attempts for the current
 * reminder (backoff 5 min → 30 min → 2 h, 4 max, same ladder as
 * the invoice delivery attempts); sent_count tracks reminders
 * actually sent. Reminders stop when the invoice is settled or
 * cancelled, or when the max is reached (status 'stopped').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reminders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('status', 20)->default('scheduled');
            $table->integer('sent_count')->default(0);
            $table->integer('attempt_count')->default(0);
            $table->date('next_reminder_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id'], 'br_invoice_uq');
            $table->index(['contract_id'], 'br_contract_idx');
            $table->index(['created_by', 'status', 'next_reminder_at'], 'br_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reminders');
    }
};
