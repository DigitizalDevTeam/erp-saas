<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 0 — financial accounts (plan §5).
 *
 * Extends the existing `bank_accounts` table rather than replacing it:
 * one treasury row per bank account carries the Treasury-specific
 * fields (type, currency, branch, limits, negative-balance policy,
 * status, code). Fields the bank account already has (holder name,
 * bank/institution name, account number, chart account, contact,
 * address) are REUSED from `bank_accounts` — never duplicated here.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). All index
 * names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_financial_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('bank_account_id');
            $table->integer('created_by');
            $table->string('code', 50);
            // bank / cash_register / mobile_wallet / funds_in_transit
            $table->string('type', 20)->default('bank');
            $table->string('currency', 3)->default('MZN');
            $table->boolean('currency_locked')->default(false);
            $table->bigInteger('branch_id')->nullable();
            $table->string('nib_or_iban', 50)->nullable();
            $table->bigInteger('owner_user_id')->nullable();
            $table->decimal('cash_limit', 20, 4)->nullable();
            $table->decimal('minimum_balance', 20, 4)->nullable();
            // block / allow / approval (D3 — block by default).
            $table->string('negative_balance_policy', 20)->default('block');
            $table->decimal('negative_balance_limit', 20, 4)->nullable();
            $table->bigInteger('negative_balance_approver_user_id')->nullable();
            // active / inactive. Deactivating blocks new activity and
            // keeps reporting; it never deletes history.
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['bank_account_id'], 'tfa_bank_account_uq');
            $table->unique(['created_by', 'code'], 'tfa_company_code_uq');
            $table->index(['created_by', 'type'], 'tfa_company_type_idx');
            $table->index(['created_by', 'status'], 'tfa_company_status_idx');
            $table->index(['branch_id'], 'tfa_branch_idx');
            $table->index(['currency'], 'tfa_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_financial_accounts');
    }
};
