<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portuguese account type label: "Ativo Atual" → "Ativo Corrente" (Current Asset sub-type only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_account_sub_types')) {
            return;
        }

        DB::table('chart_of_account_sub_types')
            ->where('name', 'Current Asset')
            ->whereIn('name_pt_br', [
                'Ativo Atual',
                'Ativo atual',
                'Ativos Atuais',
                'Ativos atuais',
            ])
            ->update(['name_pt_br' => 'Ativo Corrente']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_account_sub_types')) {
            return;
        }

        DB::table('chart_of_account_sub_types')
            ->where('name', 'Current Asset')
            ->where('name_pt_br', 'Ativo Corrente')
            ->update(['name_pt_br' => 'Ativo Atual']);
    }
};
