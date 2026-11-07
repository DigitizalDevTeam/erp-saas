<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_losses', function (Blueprint $table) {
            $table->id();
            // Per-company sequential number, allocated at creation
            // (KL-{YEAR}-{SEQ}); the kitchen refers to the loss by it.
            $table->string('loss_number', 30);
            // preparation_loss | burnt_spoiled | buffet_leftover |
            // customer_return | staff_meal | complimentary | tasting |
            // transfer (plan §6). The "buffet leftover" category is a
            // label only — Phase 4 (buffet) was skipped, so no buffet
            // is built here.
            $table->string('category', 30);
            // The product that was lost: a prepared dish (from a batch)
            // or a raw ingredient.
            $table->unsignedBigInteger('product_id');
            // Quantity in the product's stock unit (converted at record
            // time when the user entered another unit).
            $table->decimal('quantity', 12, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2);
            $table->unsignedBigInteger('warehouse_id');
            // Transfer between kitchens: the destination warehouse.
            // Only used when category is 'transfer'.
            $table->unsignedBigInteger('destination_warehouse_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            // recorded | approved | posted | rejected (plan §6).
            $table->string('status', 20)->default('recorded');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            // Reuse: the food was redirected instead of wasted. The
            // food-safety note is mandatory when reuse is flagged, and
            // the destination can never be a batch that is already sold
            // (it is one of staff_meal / complimentary / re_prepped /
            // other — never a batch reference).
            $table->boolean('reuse_flag')->default(false);
            $table->text('food_safety_note')->nullable();
            $table->string('reuse_destination', 30)->nullable();
            // Traceability: a loss links to a production order, a POS
            // order / line, and/or a batch where applicable.
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->unsignedBigInteger('pos_product_id')->nullable();
            $table->unsignedBigInteger('kitchen_production_batch_id')->nullable();
            // The stock write-off row created at posting.
            $table->unsignedBigInteger('warehouse_loss_id')->nullable();
            // True when the stock was already deducted before the loss
            // was recorded (POS cancellation after preparation): posting
            // then only creates the WarehouseLoss log row and must not
            // move stock a second time.
            $table->boolean('stock_already_deducted')->default(false);
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'loss_number'], 'kl_created_by_number_unique');
            $table->index(['created_by', 'status'], 'kl_created_by_status_idx');
            $table->index(['created_by', 'category'], 'kl_created_by_category_idx');
            $table->index(['kitchen_production_batch_id'], 'kl_batch_idx');
            $table->index(['kitchen_production_order_id'], 'kl_order_idx');
            $table->index(['pos_id'], 'kl_pos_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_losses');
    }
};
