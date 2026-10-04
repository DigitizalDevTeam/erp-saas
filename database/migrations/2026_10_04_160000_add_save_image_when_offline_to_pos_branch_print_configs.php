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

        if (! Schema::hasColumn('pos_branch_print_configs', 'save_image_when_offline')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->boolean('save_image_when_offline')->default(false)->after('use_print_agent_for_invoice');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_branch_print_configs')) {
            return;
        }

        if (Schema::hasColumn('pos_branch_print_configs', 'save_image_when_offline')) {
            Schema::table('pos_branch_print_configs', function (Blueprint $table) {
                $table->dropColumn('save_image_when_offline');
            });
        }
    }
};
