<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 1 — the ONE unified operation table (plan §5).
 *
 * One row per confirmed operation (RF01): the operation is the thing
 * everything else links to. The reference is unique per company and
 * never reused (pattern {COMPANY}-{TYPE}-{YEAR}-{SEQUENCE}); the
 * sequence is allocated only when the operation leaves Draft, so a
 * draft that is cancelled never consumes a number.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). All index
 * names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Reference is allocated when the operation leaves Draft.
            $table->string('reference', 60)->nullable();
            // receipt / payment / transfer / advance / cash_movement / adjustment
            $table->string('type', 20);
            $table->integer('company_id');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('financial_account_id')->nullable();
            // customer / vendor / employee / entity
            $table->string('counterparty_type', 20)->nullable();
            $table->bigInteger('counterparty_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            // amount_original x exchange_rate, in the company base currency.
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            // draft / pending / confirmed / rejected / cancelled / reversed
            $table->string('state', 20)->default('draft');
            // invoice / bill / payroll / pos_shift / contract / manual
            $table->string('source_type', 20)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'reference'], 'top_company_ref_uq');
            $table->index(['created_by', 'type', 'state'], 'top_company_type_idx');
            $table->index(['financial_account_id', 'state'], 'top_account_state_idx');
            $table->index(['counterparty_type', 'counterparty_id'], 'top_counterparty_idx');
            $table->index(['reversal_of_id'], 'top_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_operations');
    }
};
