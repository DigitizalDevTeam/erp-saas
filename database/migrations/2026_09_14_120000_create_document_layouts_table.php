<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('document_type', 32); // invoice|bill|purchase|quotation|pos
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('page_width')->default(794);
            $table->unsignedInteger('page_height')->default(1123);
            $table->json('elements')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'document_type']);
            $table->index(['created_by', 'document_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_layouts');
    }
};
