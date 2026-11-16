<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company mapping: chart-of-accounts account -> management P&L
 * group (+ subgroup). A mapped account's MANUAL journal postings
 * feed the management P&L (see ManagementJournalCosts). Only manual
 * journals count — system-posted journals (bills, treasury, ...) are
 * never counted here, so a cost recorded as a document can never
 * double-count with its own bill posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_account_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('chart_account_id');
            $table->string('group', 100);
            $table->string('subgroup', 100)->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'chart_account_id'], 'mgmt_acct_map_creator_acct_uq');
            $table->index(['created_by', 'group'], 'mgmt_acct_map_creator_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_account_mappings');
    }
};
