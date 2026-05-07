<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hotel_night_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('audit_date');
            $table->decimal('room_revenue', 15, 2)->default(0);
            $table->decimal('service_revenue', 15, 2)->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_night_audits');
    }
};
