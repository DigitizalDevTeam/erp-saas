<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Print Agent pairing: the agent shows a device code; a logged-in user
     * with "manage printers" enters it in Printer Settings, which binds the
     * agent to their company + the branch selected in their session. The
     * agent then needs no manual URL/branch setup.
     */
    public function up(): void
    {
        if (Schema::hasTable('pos_printer_agent_pairings')) {
            return;
        }
        Schema::create('pos_printer_agent_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 16)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('machine_name')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_printer_agent_pairings');
    }
};
