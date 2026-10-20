<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_price_rules')) {
            Schema::create('hotel_price_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->index();
                $table->string('name');
                // child | extra_person | addon
                $table->string('rule_type', 32)->index();
                // null = applies to all room types; a specific type beats the global rule
                $table->unsignedBigInteger('hotel_room_type_id')->nullable()->index();
                // only for addon rules
                $table->unsignedBigInteger('hotel_addon_id')->nullable()->index();
                // child rules: adults band the rule applies to (null = any)
                $table->unsignedInteger('adults_min')->nullable();
                $table->unsignedInteger('adults_max')->nullable();
                // quantity range the price applies to; qty_to null = and above
                $table->unsignedInteger('qty_from')->default(1);
                $table->unsignedInteger('qty_to')->nullable();
                $table->decimal('price', 15, 2)->default(0);
                // per_night | per_stay
                $table->string('price_basis', 16)->default('per_night');
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                // lower wins within the same scope
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_price_rules');
    }
};
