<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — payment attempts (plan §5, §6, CA06, CA20).
 *
 * One row per execution attempt. A retry is a NEW attempt row, never
 * a re-send of the old one. The idempotency key is unique: a repeated
 * key with the same payload returns the original result; a different
 * payload returns a conflict (CA20). A timeout sets the outcome to
 * unknown — the provider is queried before any retry (CA06).
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_payment_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('attempt_number')->default(1);
            $table->string('idempotency_key', 100);
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 100)->nullable();
            // processing / outcome_unknown / paid / failed
            $table->string('state', 20)->default('processing');
            $table->text('result')->nullable();
            // sha256 of the canonical request payload (CA20).
            $table->string('payload_hash', 64);
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['idempotency_key'], 'tpa_key_uq');
            $table->index(['payment_request_id'], 'tpa_request_idx');
            $table->index(['payment_request_id', 'attempt_number'], 'tpa_request_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_payment_attempts');
    }
};
