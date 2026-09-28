<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_branch_print_configs')) {
            return;
        }

        if (! Schema::hasColumn('pos_branch_print_configs', 'use_print_agent_for_invoice')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->boolean('use_print_agent_for_invoice')->default(false)->after('default_printer_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_branch_print_configs')) {
            return;
        }

        if (Schema::hasColumn('pos_branch_print_configs', 'use_print_agent_for_invoice')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->dropColumn('use_print_agent_for_invoice');
            });
        }
    }
};
