<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 5 — cash registers (plan §5, §6, §10, CA09).
 *
 * A cash session is the cash book for one register over one period:
 * it opens with a starting float, records cash movements, is counted
 * by denomination, and closes with a variance that is kept — together
 * with the count that produced it — forever. The count and the
 * variance are never overwritten: a re-count while the session is
 * open supersedes the draft count (audit event kept); once closed,
 * the count rows are immutable, and a reopen + re-count + re-close
 * posts only the DELTA against the variance already posted.
 *
 * The opening float is the cash already on the register account (an
 * approved opening balance or a transfer in) — the session counts
 * against it, it never invents money. If the typed float differs
 * from the register's posted balance at open, the difference is
 * stored on the session and shown as a warning; it is NOT silently
 * corrected.
 *
 * Cash movements (method cash) are balance movements on the register
 * account: each one writes a treasury entry on the session's
 * cash-movement operation, so the register's posted balance tracks
 * the physical cash. They carry no journal — like an approved
 * opening balance, they move a balance, they do not recognise income
 * or expense (the sale or payment behind the cash was already posted
 * by its own flow). Card and mobile movements are session-local
 * records only, shown separately from physical cash, and never write
 * entries. The variance adjustment and the handover DO post journals
 * (they are real Treasury movements).
 *
 * A session opened from a POS shift imports the shift once: the
 * shift must be closed, same-company, and no other session may
 * already reference it (unique index — MySQL allows many NULLs, so
 * manual sessions are unaffected). The shift's cash takings arrive
 * as one inflow movement at feed; card/credit totals are snapshotted
 * for display.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_cash_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('register_id');
            $table->bigInteger('operator_user_id');
            // A closed POS shift this session was opened from. One
            // session per shift, ever (unique); NULL for manual opens.
            $table->bigInteger('shift_id')->nullable();
            // Register currency, snapshotted at open (the register's
            // currency is locked after its first confirmed movement).
            $table->string('currency', 3)->default('MZN');
            // Register → base rate (1 when the register is the base
            // currency; required input otherwise — never guessed, D2).
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Starting float, typed at open.
            $table->decimal('opening_float', 20, 4)->default(0);
            // Signed difference between the typed float and the
            // register's posted balance at open (0 when they agree).
            $table->decimal('opening_float_difference', 20, 4)->default(0);
            // Expected physical cash = opening + cash inflows − cash
            // outflows (handovers included). Recomputed on every
            // movement; snapshotted at close.
            $table->decimal('expected_balance', 20, 4)->default(0);
            // Counted physical cash from the denomination count.
            $table->decimal('counted_balance', 20, 4)->nullable();
            // Signed variance: counted − expected. Negative is a
            // shortage, positive a surplus.
            $table->decimal('variance_amount', 20, 4)->nullable();
            // Signed variance already posted as an adjustment (0
            // until the first close). A re-close posts only the delta.
            $table->decimal('variance_posted_amount', 20, 4)->default(0);
            $table->text('variance_reason')->nullable();
            $table->bigInteger('variance_approved_by')->nullable();
            $table->timestamp('variance_approved_at')->nullable();
            $table->bigInteger('variance_operation_id')->nullable();
            $table->bigInteger('variance_journal_id')->nullable();
            // Non-cash totals, shown separately from physical cash.
            $table->decimal('card_total', 20, 4)->default(0);
            $table->decimal('mobile_total', 20, 4)->default(0);
            // POS shift snapshot (only when opened from a shift).
            $table->decimal('shift_cash_total', 20, 4)->nullable();
            $table->decimal('shift_card_total', 20, 4)->nullable();
            $table->decimal('shift_credit_total', 20, 4)->nullable();
            $table->decimal('shift_difference', 20, 4)->nullable();
            $table->decimal('shift_closing_balance', 20, 4)->nullable();
            // The session's own cash-movement operation (created
            // lazily on the first cash movement).
            $table->bigInteger('operation_id')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->bigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->bigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            // open / closed / locked
            $table->string('state', 20)->default('open');
            $table->timestamps();

            $table->unique(['shift_id'], 'tcs_shift_uq');
            $table->index(['created_by', 'state'], 'tcs_company_state_idx');
            $table->index(['register_id'], 'tcs_register_idx');
            $table->index(['operator_user_id'], 'tcs_operator_idx');
            $table->index(['operation_id'], 'tcs_operation_idx');
        });

        Schema::create('treasury_cash_counts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            // One row per denomination in the count.
            $table->decimal('denomination', 20, 4);
            $table->integer('count')->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->timestamps();

            $table->index(['cash_session_id'], 'tcc_session_idx');
        });

        Schema::create('treasury_cash_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->bigInteger('recorded_by')->nullable();
            // inflow / outflow / float_top_up / withdrawal / handover
            $table->string('type', 20);
            // cash / card / mobile — only cash writes entries.
            $table->string('method', 20)->default('cash');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            // Handover only: the Phase 4 transfer that moved the money.
            $table->bigInteger('transfer_id')->nullable();
            // Cash movements only: the treasury entry this movement wrote.
            $table->bigInteger('entry_id')->nullable();
            $table->timestamps();

            $table->index(['cash_session_id'], 'tcm_session_idx');
            $table->index(['transfer_id'], 'tcm_transfer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_cash_movements');
        Schema::dropIfExists('treasury_cash_counts');
        Schema::dropIfExists('treasury_cash_sessions');
    }
};
