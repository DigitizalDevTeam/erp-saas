<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouse_user')) {
            Schema::create('warehouse_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['warehouse_id', 'user_id']);
            });
        }

        if (Schema::hasColumn('users', 'warehouse_id')) {
            $rows = DB::table('users')
                ->whereNotNull('warehouse_id')
                ->where('warehouse_id', '>', 0)
                ->get(['id', 'warehouse_id']);

            foreach ($rows as $row) {
                $exists = DB::table('warehouse_user')
                    ->where('warehouse_id', $row->warehouse_id)
                    ->where('user_id', $row->id)
                    ->exists();
                if (! $exists) {
                    DB::table('warehouse_user')->insert([
                        'warehouse_id' => $row->warehouse_id,
                        'user_id' => $row->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_user');
    }
};
