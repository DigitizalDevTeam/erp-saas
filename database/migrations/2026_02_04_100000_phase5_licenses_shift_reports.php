<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: Licenses (max POS users per plan), Shift close (tips, notes, summary).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'max_pos_users')) {
                $table->integer('max_pos_users')->default(-1)->after('pos')->comment('-1 = unlimited');
            }
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_shifts', 'tips')) {
                $table->decimal('tips', 16, 2)->default(0)->after('closing_balance');
            }
            if (!Schema::hasColumn('pos_shifts', 'notes')) {
                $table->text('notes')->nullable()->after('tips');
            }
            if (!Schema::hasColumn('pos_shifts', 'sales_count')) {
                $table->unsignedInteger('sales_count')->default(0)->after('notes');
            }
            if (!Schema::hasColumn('pos_shifts', 'sales_total')) {
                $table->decimal('sales_total', 16, 2)->default(0)->after('sales_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'max_pos_users')) {
                $table->dropColumn('max_pos_users');
            }
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            foreach (['sales_total', 'sales_count', 'notes', 'tips'] as $col) {
                if (Schema::hasColumn('pos_shifts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
