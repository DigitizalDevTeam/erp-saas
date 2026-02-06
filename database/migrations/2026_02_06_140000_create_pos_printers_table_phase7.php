<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7: Kitchen/Bar printer configuration per branch.
     */
    public function up(): void
    {
        Schema::create('pos_printers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('type', 20); // kitchen | bar
            $table->string('connection', 100)->default('browser'); // 'browser' | 'IP:port' e.g. 192.168.1.10:9100
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_printers');
    }
};
