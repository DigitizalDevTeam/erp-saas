<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irps_brackets', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Salary range (monthly)
            $table->decimal('salary_from', 15, 2)->default(0);
            $table->decimal('salary_to', 15, 2)->nullable(); // null = no upper limit

            // Dependents exact match (simple + predictable)
            $table->unsignedInteger('dependents')->default(0);

            // Generic formula: tax = max(0, salary * rate% - deduction) + fixed
            $table->decimal('rate_percent', 8, 4)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->decimal('fixed', 15, 2)->default(0);

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['created_by', 'dependents', 'salary_from', 'salary_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irps_brackets');
    }
};

