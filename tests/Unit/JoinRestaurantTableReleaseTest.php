<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\PosTable;
use App\Models\PosTableAssignment;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Join Tables floor fix: releasing a joined source table must free the
 * table row AND unassign it from its open shifts. Before this fix only
 * the table row was freed, so the shift page's Table Assignments table
 * and Transfer Tables popup still showed the table as that waiter's
 * (it still looked joined), and waiter-only floors kept listing it.
 */
class JoinRestaurantTableReleaseTest extends TestCase
{
    private PosController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos_tables', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('current_guests')->nullable();
            $table->string('customer_name')->nullable();
            $table->integer('waiter_user_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_table_assignments', function ($table) {
            $table->increments('id');
            $table->integer('shift_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        $this->controller = new class extends PosController
        {
            public function runRelease(int $tableId, int $creatorId, array $openShiftIds): void
            {
                $this->releaseJoinedRestaurantTable($tableId, $creatorId, $openShiftIds);
            }
        };
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_table_assignments');
        Schema::dropIfExists('pos_tables');

        parent::tearDown();
    }

    private function occupiedTable(): PosTable
    {
        $table = new PosTable();
        $table->name = '05';
        $table->status = PosTable::STATUS_OCCUPIED;
        $table->current_guests = 4;
        $table->customer_name = 'Walk-in';
        $table->waiter_user_id = 5;
        $table->created_by = 1;
        $table->save();

        return $table;
    }

    private function assignment(int $tableId, int $shiftId, ?string $unassignedAt = null): PosTableAssignment
    {
        $a = new PosTableAssignment();
        $a->shift_id = $shiftId;
        $a->table_id = $tableId;
        $a->user_id = 5;
        $a->assigned_at = now()->subHour();
        $a->unassigned_at = $unassignedAt;
        $a->created_by = 1;
        $a->save();

        return $a;
    }

    public function test_release_frees_table_and_unassigns_open_shift(): void
    {
        $table = $this->occupiedTable();
        $assignment = $this->assignment($table->id, 101);

        $this->controller->runRelease($table->id, 1, [101]);

        $table = $table->fresh();
        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->status);
        $this->assertSame(0, (int) $table->current_guests);
        $this->assertNull($table->customer_name);
        $this->assertNull($table->waiter_user_id);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
    }

    public function test_assignment_on_other_shift_is_untouched(): void
    {
        $table = $this->occupiedTable();
        $other = $this->assignment($table->id, 202);

        $this->controller->runRelease($table->id, 1, [101]);

        $this->assertNull($other->fresh()->unassigned_at);
        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->fresh()->status);
    }

    public function test_already_unassigned_row_is_untouched(): void
    {
        $table = $this->occupiedTable();
        $old = now()->subHours(2)->format('Y-m-d H:i:s');
        $assignment = $this->assignment($table->id, 101, $old);

        $this->controller->runRelease($table->id, 1, [101]);

        $this->assertSame($old, $assignment->fresh()->unassigned_at->format('Y-m-d H:i:s'));
    }

    public function test_release_without_assignments_still_frees_table(): void
    {
        $table = $this->occupiedTable();

        $this->controller->runRelease($table->id, 1, [101]);

        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->fresh()->status);
    }
}
