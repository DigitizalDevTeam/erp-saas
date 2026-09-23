<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Soft-remove sent POS lines from the restaurant cart (admin delete). */
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_products', 'removed_from_cart_at')) {
                $table->timestamp('removed_from_cart_at')->nullable()->after('voided_by');
            }
            if (! Schema::hasColumn('pos_products', 'removed_from_cart_by')) {
                $table->unsignedBigInteger('removed_from_cart_by')->nullable()->after('removed_from_cart_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'removed_from_cart_by')) {
                $table->dropColumn('removed_from_cart_by');
            }
            if (Schema::hasColumn('pos_products', 'removed_from_cart_at')) {
                $table->dropColumn('removed_from_cart_at');
            }
        });
    }
};
