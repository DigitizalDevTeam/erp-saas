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

        if (! Schema::hasColumn('pos_branch_print_configs', 'default_printer_id')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->unsignedBigInteger('default_printer_id')->nullable()->after('bar_printer_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_branch_print_configs')) {
            return;
        }

        if (Schema::hasColumn('pos_branch_print_configs', 'default_printer_id')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->dropColumn('default_printer_id');
            });
        }
    }
};
