<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing contract audit events
 * (plan §5, §9 audit trail).
 *
 * Append-only: every lifecycle transition, approval, amendment,
 * suspension, resume, end and cancel writes one row with actor,
 * action, reason and before/after values. Nothing here is ever
 * edited or deleted; the Phase 4 audit-trail screen reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_contract_audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id')->nullable();
            $table->bigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['contract_id'], 'bcae_contract_idx');
            $table->index(['created_by'], 'bcae_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_contract_audit_events');
    }
};
