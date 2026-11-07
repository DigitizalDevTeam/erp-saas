<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing contract lines (plan §5).
 *
 * One row per contract line: product/service (optional — a line
 * may be a free-text service), quantity, unit price, discount,
 * tax (from the Tax model, never hard-coded), optional revenue
 * account and cost centre. Lines belong to the contract directly;
 * revisions snapshot them as JSON, so an issued invoice never
 * changes when a contract is amended.
 *
 * amount = quantity * unit_price - discount_amount, computed by
 * the service with bcmath, never float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_contract_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('product_id')->nullable();
            $table->string('description', 250);
            $table->decimal('quantity', 20, 4)->default('1.0000');
            $table->decimal('unit_price', 20, 4)->default('0.0000');
            $table->decimal('discount_amount', 20, 4)->default('0.0000');
            $table->bigInteger('tax_id')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['contract_id'], 'bcl_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_contract_lines');
    }
};
