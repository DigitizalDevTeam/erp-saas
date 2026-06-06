<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('source')->nullable();
            $table->string('mailable_class')->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
