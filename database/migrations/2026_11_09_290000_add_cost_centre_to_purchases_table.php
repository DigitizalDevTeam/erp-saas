<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost Centre Phase 7 — purchasing: a purchase order can carry a
     * cost centre (+ project) so a budget commitment can be reserved
     * at PO stage and converted at invoice posting.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_centre_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('project_id')->nullable()->after('cost_centre_id');
            $table->index(['created_by', 'cost_centre_id'], 'pur_creator_centre_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('pur_creator_centre_idx');
            $table->dropColumn(['cost_centre_id', 'project_id']);
        });
    }
};
