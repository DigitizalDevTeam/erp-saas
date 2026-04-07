<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash In during a POS shift (top-ups / float added).
     */
    public function up(): void
    {
        Schema::create('pos_cash_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('source_account_id')->default(0); // bank_accounts.id (optional)
            $table->unsignedBigInteger('target_account_id')->default(0); // bank_accounts.id (optional; usually cash drawer)
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();

            $table->index(['shift_id']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_ins');
    }
};

