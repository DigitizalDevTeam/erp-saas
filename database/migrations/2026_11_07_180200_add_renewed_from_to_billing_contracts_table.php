<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 3 — renewal link on the contract.
 *
 * A renewed contract points back at the contract it was renewed
 * from, so the renewal chain is visible from either side
 * (old contract → renewals, new contract → renewed_from_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_contracts', function (Blueprint $table) {
            $table->bigInteger('renewed_from_id')->nullable()->after('current_revision_id');
            $table->index(['renewed_from_id'], 'bc_renewed_from_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_contracts', function (Blueprint $table) {
            $table->dropIndex('bc_renewed_from_idx');
            $table->dropColumn('renewed_from_id');
        });
    }
};
