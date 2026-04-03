<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add expense_category_id when the expenses table was created without it (older DB / partial migrations).
     */
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'expense_category_id')) {
                $table->integer('expense_category_id')->default(0);
            }
            if (! Schema::hasColumn('expenses', 'status')) {
                $table->integer('status')->default(0);
            }
            

        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'expense_category_id')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('expense_category_id');
            $table->dropColumn('status');
        });
    }
};
