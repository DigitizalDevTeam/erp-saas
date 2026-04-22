<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_user')) {
            Schema::create('company_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 100)->nullable();
                $table->boolean('is_default')->default(false);
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->unique(['company_id', 'user_id']);
                $table->index(['user_id', 'status']);
                $table->index(['company_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
