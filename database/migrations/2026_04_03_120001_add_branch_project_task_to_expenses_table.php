<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns expected by Expense / ExpenseRecordController when the table predates the full schema.
     */
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'branch_id')) {
                $table->integer('branch_id')->default(0);
            }
            if (! Schema::hasColumn('expenses', 'project_id')) {
                $table->integer('project_id')->default(0);
            }
            if (! Schema::hasColumn('expenses', 'task_id')) {
                $table->integer('task_id')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'branch_id')) {
                $table->dropColumn('branch_id');
            }
            if (Schema::hasColumn('expenses', 'project_id')) {
                $table->dropColumn('project_id');
            }
            if (Schema::hasColumn('expenses', 'task_id')) {
                $table->dropColumn('task_id');
            }
        });
    }
};
