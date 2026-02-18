<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2: Cash drop – record withdrawals from register during shift.
     */
    public function up(): void
    {
        Schema::create('pos_cash_drops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_drops');
    }
};
