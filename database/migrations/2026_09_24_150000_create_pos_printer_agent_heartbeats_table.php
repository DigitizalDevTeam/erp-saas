<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_printer_agent_heartbeats')) {
            Schema::create('pos_printer_agent_heartbeats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('created_by')->default(0);
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->string('machine_name')->nullable();
                $table->timestamps();

                $table->unique('branch_id');
                $table->index(['branch_id', 'last_heartbeat_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_printer_agent_heartbeats');
    }
};
