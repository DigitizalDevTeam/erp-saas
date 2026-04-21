<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('warehouse_losses')) {
            return;
        }

        Schema::table('warehouse_losses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_losses', 'write_off_no')) {
                $table->string('write_off_no', 50)->nullable()->after('id');
            }
            if (!Schema::hasColumn('warehouse_losses', 'issuer_name')) {
                $table->string('issuer_name', 150)->nullable()->after('reason');
            }
            if (!Schema::hasColumn('warehouse_losses', 'authorized_by')) {
                $table->string('authorized_by', 150)->nullable()->after('issuer_name');
            }
            if (!Schema::hasColumn('warehouse_losses', 'witness_name')) {
                $table->string('witness_name', 150)->nullable()->after('authorized_by');
            }
            if (!Schema::hasColumn('warehouse_losses', 'delivery_note_required')) {
                $table->boolean('delivery_note_required')->default(false)->after('witness_name');
            }
            if (!Schema::hasColumn('warehouse_losses', 'delivery_destination')) {
                $table->string('delivery_destination', 255)->nullable()->after('delivery_note_required');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('warehouse_losses')) {
            return;
        }

        Schema::table('warehouse_losses', function (Blueprint $table) {
            foreach ([
                'delivery_destination',
                'delivery_note_required',
                'witness_name',
                'authorized_by',
                'issuer_name',
                'write_off_no',
            ] as $col) {
                if (Schema::hasColumn('warehouse_losses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

