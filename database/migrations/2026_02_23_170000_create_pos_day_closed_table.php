<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track closed POS days so that no new shifts can be opened unless admin reopens.
     */
    public function up(): void
    {
        Schema::create('pos_day_closed', function (Blueprint $table) {
            $table->id();
            $table->date('closed_date');
            $table->unique(['closed_date', 'created_by']);
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at')->useCurrent();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_day_closed');
    }
};
