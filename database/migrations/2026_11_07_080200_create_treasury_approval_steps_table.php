<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — approval steps (plan §5, §6).
 *
 * One row per approval level of a payment request, generated from the
 * company's approval rules when the request is submitted. Levels are
 * decided in order (lowest pending level first). `eligible_user_ids`
 * is the snapshot of users who may decide this level; a time-limited
 * substitute may decide in their place.
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_approval_steps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('rule_version')->default(1);
            $table->integer('level')->default(1);
            $table->string('permission', 100)->default('approve treasury payments');
            $table->integer('required_votes')->default(1);
            $table->json('eligible_user_ids')->nullable();
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            // pending / approved / rejected / returned / invalidated
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['payment_request_id'], 'tas_request_idx');
            $table->index(['payment_request_id', 'level'], 'tas_request_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_approval_steps');
    }
};
