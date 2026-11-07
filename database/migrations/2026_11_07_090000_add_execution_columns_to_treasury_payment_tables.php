<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 3 — execution columns.
 *
 * Payment requests track what has actually been paid (partials):
 * paid_amount_original / paid_amount_base start at zero and grow
 * with each executed part; the request is PAID only when the paid
 * base amount reaches the approved base amount.
 *
 * Attempts carry the executed amounts plus the journal and entry
 * they posted, so an idempotent replay (CA20) can return the
 * original outcome instead of posting a second movement (CA18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_payment_requests', function (Blueprint $table) {
            $table->decimal('paid_amount_original', 20, 4)->default(0)->after('amount_base');
            $table->decimal('paid_amount_base', 20, 4)->default(0)->after('paid_amount_original');
        });

        Schema::table('treasury_payment_attempts', function (Blueprint $table) {
            $table->decimal('amount_original', 20, 4)->nullable()->after('provider_reference');
            $table->decimal('amount_base', 20, 4)->nullable()->after('amount_original');
            $table->string('currency', 3)->nullable()->after('amount_base');
            $table->bigInteger('journal_id')->nullable()->after('currency');
            $table->bigInteger('entry_id')->nullable()->after('journal_id');

            $table->index(['journal_id'], 'tpa_journal_idx');
            $table->index(['entry_id'], 'tpa_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_payment_attempts', function (Blueprint $table) {
            $table->dropIndex('tpa_journal_idx');
            $table->dropIndex('tpa_entry_idx');
            $table->dropColumn(['amount_original', 'amount_base', 'currency', 'journal_id', 'entry_id']);
        });

        Schema::table('treasury_payment_requests', function (Blueprint $table) {
            $table->dropColumn(['paid_amount_original', 'paid_amount_base']);
        });
    }
};
