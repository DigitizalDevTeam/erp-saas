<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portuguese chart account descriptions: Ativo Atual → Ativo Corrente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chart_of_accounts') && Schema::hasColumn('chart_of_accounts', 'description_pt_br')) {
            $replacements = [
                'Ativos Atuais' => 'Ativos Correntes',
                'Ativos atuais' => 'Ativos Correntes',
                'Ativo Atual' => 'Ativo Corrente',
                'Ativo atual' => 'Ativo Corrente',
            ];

            foreach ($replacements as $from => $to) {
                DB::table('chart_of_accounts')
                    ->where('description_pt_br', 'like', '%' . $from . '%')
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($from, $to) {
                        foreach ($rows as $row) {
                            DB::table('chart_of_accounts')
                                ->where('id', $row->id)
                                ->update([
                                    'description_pt_br' => str_replace($from, $to, (string) $row->description_pt_br),
                                ]);
                        }
                    });
            }
        }

        // Catch any Current Asset subtypes still missing the Portuguese label.
        if (Schema::hasTable('chart_of_account_sub_types')) {
            DB::table('chart_of_account_sub_types')
                ->where('name', 'Current Asset')
                ->where(function ($q) {
                    $q->whereNull('name_pt_br')
                        ->orWhere('name_pt_br', '')
                        ->orWhere('name_pt_br', 'Current Asset')
                        ->orWhereIn('name_pt_br', [
                            'Ativo Atual',
                            'Ativo atual',
                            'Ativos Atuais',
                            'Ativos atuais',
                        ]);
                })
                ->update(['name_pt_br' => 'Ativo Corrente']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_accounts') || ! Schema::hasColumn('chart_of_accounts', 'description_pt_br')) {
            return;
        }

        $replacements = [
            'Ativos Correntes' => 'Ativos Atuais',
            'Ativo Corrente' => 'Ativo Atual',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('chart_of_accounts')
                ->where('description_pt_br', 'like', '%' . $from . '%')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($from, $to) {
                    foreach ($rows as $row) {
                        DB::table('chart_of_accounts')
                            ->where('id', $row->id)
                            ->update([
                                'description_pt_br' => str_replace($from, $to, (string) $row->description_pt_br),
                            ]);
                    }
                });
        }
    }
};
