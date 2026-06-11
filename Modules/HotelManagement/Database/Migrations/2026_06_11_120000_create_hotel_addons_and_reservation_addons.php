<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('other');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('billing_mode', 16)->default('per_stay');
            $table->boolean('taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['created_by', 'is_active']);
        });

        Schema::create('hotel_reservation_addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_reservation_id');
            $table->unsignedBigInteger('hotel_addon_id');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->string('billing_mode', 16)->default('per_stay');
            $table->unsignedBigInteger('hotel_folio_line_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_reservation_id')->references('id')->on('hotel_reservations')->onDelete('cascade');
            $table->foreign('hotel_addon_id')->references('id')->on('hotel_addons')->onDelete('restrict');
        });

        if (Schema::hasTable('hotel_folio_lines')) {
            Schema::table('hotel_folio_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('hotel_folio_lines', 'hotel_addon_id')) {
                    $table->unsignedBigInteger('hotel_addon_id')->nullable()->after('source_pos_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_folio_lines') && Schema::hasColumn('hotel_folio_lines', 'hotel_addon_id')) {
            Schema::table('hotel_folio_lines', function (Blueprint $table) {
                $table->dropColumn('hotel_addon_id');
            });
        }

        Schema::dropIfExists('hotel_reservation_addons');
        Schema::dropIfExists('hotel_addons');
    }
};
