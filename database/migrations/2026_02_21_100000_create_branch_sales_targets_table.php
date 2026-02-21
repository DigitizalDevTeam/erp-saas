<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_sales_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->char('month', 7); // YYYY-MM
            $table->decimal('target_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->unique(['branch_id', 'month']);
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_sales_targets');
    }
};
