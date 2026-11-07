<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — approval decisions (plan §5, §6).
 *
 * Append-only history: every decision on a step is kept, including
 * decisions later invalidated by a change to the approved data
 * (CA05) — the step status shows they no longer count, the rows
 * themselves are never deleted.
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_approval_decisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->bigInteger('approval_step_id');
            $table->integer('actor_id');
            // approve / reject / return
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            // Set when the actor decided as a time-limited substitute.
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['payment_request_id'], 'tad_request_idx');
            $table->index(['approval_step_id'], 'tad_step_idx');
            $table->index(['actor_id'], 'tad_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_approval_decisions');
    }
};
