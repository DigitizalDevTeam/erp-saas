<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_requisition_id');
            $table->unsignedBigInteger('ingredient_product_id');
            // Required for the plan / order, in the ingredient's stock unit.
            $table->decimal('required_quantity', 12, 4);
            // What the kitchen warehouse already holds when requested.
            $table->decimal('available_quantity', 12, 4)->default(0);
            // required - available (never below zero): what must be moved in.
            $table->decimal('shortage_quantity', 12, 4)->default(0);
            // Actually issued so far (grows across partial issues).
            $table->decimal('issued_quantity', 12, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['kitchen_requisition_id'], 'krl_requisition_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_requisition_lines');
    }
};
