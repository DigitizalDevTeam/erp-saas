<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_daily_specials', function (Blueprint $table) {
            $table->id();
            $table->date('special_date');
            // The dish sold on the POS that day. Every special gets
            // its own product row (created by the specials screen):
            // its sale_price IS the special price, and it is hidden
            // from the POS (show_on_pos = 0) except on its date, so a
            // special lives for its date only.
            $table->unsignedBigInteger('product_id');
            // The approved recipe the dish was copied from (null for
            // an on-the-spot dish made up for one guest).
            $table->unsignedBigInteger('kitchen_recipe_id')->nullable();
            // The menu dish this special was copied from (null for an
            // on-the-spot dish).
            $table->unsignedBigInteger('source_product_id')->nullable();
            // Planned portions for the day (planning number; the POS
            // does not hard-stop at it in v1).
            $table->decimal('quantity_planned', 12, 4);
            $table->decimal('selling_price', 15, 4);
            // active -> expired (date passed) or cancelled.
            $table->string('status', 20)->default('active');
            // True when the chef made the dish up on the spot for one
            // guest (not on the menu, ingredients entered at creation).
            $table->boolean('is_on_the_spot')->default(false);
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'product_id', 'special_date'], 'kds_creator_prod_date_uq');
            $table->index(['created_by', 'special_date'], 'kds_creator_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_daily_specials');
    }
};
