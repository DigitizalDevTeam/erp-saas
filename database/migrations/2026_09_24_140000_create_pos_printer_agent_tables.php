<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_agent_printers')) {
            Schema::create('pos_agent_printers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('created_by')->default(0);
                $table->string('system_name');
                $table->string('display_name')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'system_name'], 'pos_agent_printers_branch_system_unique');
                $table->index(['branch_id', 'created_by']);
            });
        }

        if (! Schema::hasTable('pos_branch_print_configs')) {
            Schema::create('pos_branch_print_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('created_by')->default(0);
                $table->unsignedBigInteger('kitchen_printer_id')->nullable();
                $table->unsignedBigInteger('bar_printer_id')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'created_by'], 'pos_branch_print_configs_branch_creator_unique');
            });
        }

        if (! Schema::hasTable('pos_print_jobs')) {
            Schema::create('pos_print_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('created_by')->default(0);
                $table->unsignedBigInteger('pos_id')->nullable();
                $table->string('zone', 20); // kitchen | bar
                $table->string('system_printer_name');
                $table->string('title')->nullable();
                $table->json('payload');
                $table->string('status', 20)->default('pending'); // pending | printing | done | failed
                $table->text('error_message')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('printed_at')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status', 'id'], 'pos_print_jobs_poll_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_jobs');
        Schema::dropIfExists('pos_branch_print_configs');
        Schema::dropIfExists('pos_agent_printers');
    }
};
