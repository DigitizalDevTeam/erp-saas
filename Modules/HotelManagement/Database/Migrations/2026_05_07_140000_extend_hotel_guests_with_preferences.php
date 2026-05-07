<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE hotel_guests MODIFY id_type ENUM('id','passport','letter') NULL");

        Schema::table('hotel_guests', function (Blueprint $table) {
            $table->string('nationality', 100)->nullable()->after('id_number');
            $table->unsignedBigInteger('preferred_room_type_id')->nullable()->after('nationality');
            $table->string('preferred_floor', 50)->nullable()->after('preferred_room_type_id');
            $table->string('preferred_payment_method', 50)->nullable()->after('preferred_floor');
            $table->decimal('credit_limit', 15, 2)->default(0)->after('preferred_payment_method');
            $table->text('notes')->nullable()->after('credit_limit');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_guests', function (Blueprint $table) {
            $table->dropColumn([
                'nationality',
                'preferred_room_type_id',
                'preferred_floor',
                'preferred_payment_method',
                'credit_limit',
                'notes',
            ]);
        });

        DB::statement("ALTER TABLE hotel_guests MODIFY id_type ENUM('id','passport') NULL");
    }
};
