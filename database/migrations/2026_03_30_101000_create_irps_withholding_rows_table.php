<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irps_withholding_rows', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->decimal('salary_from', 15, 2)->default(0);
            $table->decimal('salary_to', 15, 2)->nullable(); // null = no upper bound

            // Base amount to withhold at the lower limit (per dependents)
            $table->decimal('base_dep0', 15, 2)->nullable();
            $table->decimal('base_dep1', 15, 2)->nullable();
            $table->decimal('base_dep2', 15, 2)->nullable();
            $table->decimal('base_dep3', 15, 2)->nullable();
            $table->decimal('base_dep4plus', 15, 2)->nullable();

            // Coefficient applied to each additional unit above the lower bound (e.g. 0.15)
            $table->decimal('coefficient', 10, 4)->nullable();

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['created_by', 'salary_from', 'salary_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irps_withholding_rows');
    }
};

