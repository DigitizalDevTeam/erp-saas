<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_buffet_events', function (Blueprint $table) {
            $table->id();
            // Per-company number: BF-{YEAR}-{SEQ} (same shape as KL- / KP-).
            $table->string('event_number', 30);
            $table->date('event_date');
            // breakfast / lunch / dinner (same values as the plans).
            $table->string('meal_service', 20)->nullable();
            $table->string('name', 150)->nullable();
            // Free-text link to a conference / event (no foreign key:
            // events live outside the kitchen module).
            $table->string('event_link', 150)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            // Where the expected covers come from: hotel occupancy or
            // a headcount typed by the chef.
            $table->string('covers_source', 20)->default('manual');
            $table->integer('expected_covers')->default(0);
            // The occupancy snapshot the covers were taken from, so the
            // chef can see why the number is what it is (plan §11.4).
            $table->json('occupancy_snapshot')->nullable();
            // Per-person charge for the buffet (info: the till charges
            // per cover, never per dish).
            $table->decimal('price_per_person', 15, 2)->nullable();
            // draft -> prepared -> completed, or cancelled.
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->integer('prepared_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'event_number'], 'kbe_creator_number_uq');
            $table->index(['created_by', 'event_date'], 'kbe_creator_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_buffet_events');
    }
};
