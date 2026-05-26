<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'product_services';
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexes = [
            'product_services_admin_list_idx' => '(created_by, category_id, name)',
            'product_services_admin_name_idx' => '(created_by, name)',
        ];

        foreach ($indexes as $name => $columns) {
            if ($this->indexExists($table, $name)) {
                continue;
            }

            try {
                DB::statement("CREATE INDEX {$name} ON {$table} {$columns}");
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (
                    str_contains($message, 'Duplicate')
                    || str_contains($message, '1061')
                    || str_contains($message, 'already exists')
                ) {
                    continue;
                }
                throw $e;
            }
        }
    }

    public function down(): void
    {
        $table = 'product_services';
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (['product_services_admin_list_idx', 'product_services_admin_name_idx'] as $name) {
            if (! $this->indexExists($table, $name)) {
                continue;
            }
            try {
                DB::statement("DROP INDEX {$name} ON {$table}");
            } catch (\Throwable $e) {
                // Ignore if already dropped
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($row->cnt ?? 0) > 0;
    }
};
