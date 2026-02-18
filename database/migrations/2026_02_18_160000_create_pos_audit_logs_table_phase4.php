<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4: POS audit log – who did what, when.
     */
    public function up(): void
    {
        Schema::create('pos_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0); // company owner
            $table->unsignedBigInteger('user_id'); // who performed the action
            $table->string('action', 80); // open_table, close_table, send_order, cancel_order, discount, cash_drop, close_shift, reopen_table, refund
            $table->string('subject_type', 60)->nullable(); // pos_table, pos, pos_product, pos_shift, pos_payment, pos_cash_drop
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable(); // extra data (e.g. table name, amount)
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_audit_logs');
    }
};
