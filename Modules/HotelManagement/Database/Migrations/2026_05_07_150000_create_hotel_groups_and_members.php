<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureInnoDb('hotel_guests');
        $this->ensurePrimaryKeyOnId('hotel_guests');

        if (! Schema::hasTable('hotel_groups')) {
            Schema::create('hotel_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('company_name')->nullable();
                $table->string('contact_name')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
            });
        }

        $this->ensureInnoDb('hotel_groups');

        if (! Schema::hasTable('hotel_group_members')) {
            Schema::create('hotel_group_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hotel_group_id');
                $table->unsignedBigInteger('hotel_guest_id');
                $table->string('role', 20)->default('adult');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->ensureInnoDb('hotel_group_members');
        $this->alignColumnType('hotel_group_members', 'hotel_guest_id', 'hotel_guests', 'id');
        $this->alignColumnType('hotel_group_members', 'hotel_group_id', 'hotel_groups', 'id');
        $this->addForeignKeyIfMissing(
            'hotel_group_members',
            'hotel_group_members_hotel_group_id_foreign',
            'hotel_group_id',
            'hotel_groups',
            'id',
            'cascade'
        );
        $this->addForeignKeyIfMissing(
            'hotel_group_members',
            'hotel_group_members_hotel_guest_id_foreign',
            'hotel_guest_id',
            'hotel_guests',
            'id',
            'cascade'
        );

        if (Schema::hasTable('hotel_reservations') && Schema::hasColumn('hotel_reservations', 'hotel_guest_id')) {
            if (! Schema::hasColumn('hotel_reservations', 'hotel_group_id')) {
                Schema::table('hotel_reservations', function (Blueprint $table) {
                    $table->unsignedBigInteger('hotel_group_id')->nullable()->after('hotel_guest_id');
                });
            }
            $this->addForeignKeyIfMissing(
                'hotel_reservations',
                'hotel_reservations_hotel_group_id_foreign',
                'hotel_group_id',
                'hotel_groups',
                'id',
                'set null'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_reservations') && Schema::hasColumn('hotel_reservations', 'hotel_group_id')) {
            $this->dropForeignKeyIfExists('hotel_reservations', 'hotel_reservations_hotel_group_id_foreign');
            Schema::table('hotel_reservations', function (Blueprint $table) {
                $table->dropColumn('hotel_group_id');
            });
        }

        $this->dropForeignKeyIfExists('hotel_group_members', 'hotel_group_members_hotel_guest_id_foreign');
        $this->dropForeignKeyIfExists('hotel_group_members', 'hotel_group_members_hotel_group_id_foreign');
        Schema::dropIfExists('hotel_group_members');
        Schema::dropIfExists('hotel_groups');
    }

    private function ensureInnoDb(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $row = DB::selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        if ($row && strtoupper((string) $row->ENGINE) !== 'INNODB') {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }
    }

    private function alignColumnType(string $table, string $column, string $refTable, string $refColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($refTable)) {
            return;
        }

        if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($refTable, $refColumn)) {
            return;
        }

        $ref = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$refTable, $refColumn]
        );

        if (! $ref) {
            return;
        }

        $nullable = strtoupper((string) $ref->IS_NULLABLE) === 'YES' ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$ref->COLUMN_TYPE} {$nullable}");
    }

    private function ensurePrimaryKeyOnId(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $hasPk = collect(DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'"))->isNotEmpty();
        if ($hasPk) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'FOREIGN KEY']
        );

        return $row !== null;
    }

    private function addForeignKeyIfMissing(
        string $table,
        string $constraint,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referencedTable)) {
            return;
        }

        if ($this->foreignKeyExists($table, $constraint)) {
            return;
        }

        $deleteClause = match ($onDelete) {
            'cascade' => 'ON DELETE CASCADE',
            'set null' => 'ON DELETE SET NULL',
            default => '',
        };

        DB::statement(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) "
            . "REFERENCES `{$referencedTable}` (`{$referencedColumn}`) {$deleteClause}"
        );
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        if (! Schema::hasTable($table) || ! $this->foreignKeyExists($table, $constraint)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
    }
};
