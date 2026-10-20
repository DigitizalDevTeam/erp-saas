<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\PosTable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Restaurant table auto-close after payment: the table must be released
 * once every payable check of the current shift is paid, and must stay
 * occupied while another check of the same shift is still open.
 */
class PosTableAutoCloseTest extends TestCase
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
        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->string('pos_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_payments', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->decimal('amount', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        $this->controller = new class extends PosController
        {
            public function runCloseCheck(int $tableId, int $userId, ?int $shiftId = null): void
            {
                $this->closeRestaurantTableIfFullyPaid($tableId, $userId, $shiftId);
            }
        };
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('pos_tables');

        parent::tearDown();
    }

    private function occupiedTable(): PosTable
    {
        $table = new PosTable();
        $table->name = '04';
        $table->status = PosTable::STATUS_OCCUPIED;
        $table->current_guests = 3;
        $table->customer_name = 'Walk-in';
        $table->waiter_user_id = 5;
        $table->created_by = 1;
        $table->save();

        return $table;
    }

    private function check(PosTable $table, int $shiftId, bool $withItems = true, bool $paid = false): Pos
    {
        $pos = new Pos();
        $pos->pos_id = 'PDV' . random_int(10000, 99999);
        $pos->table_id = $table->id;
        $pos->shift_id = $shiftId;
        $pos->created_by = 1;
        $pos->save();

        if ($withItems) {
            $line = new PosProduct();
            $line->pos_id = $pos->id;
            $line->product_id = 10;
            $line->save();
        }

        if ($paid) {
            $payment = new PosPayment();
            $payment->pos_id = $pos->id;
            $payment->amount = 100;
            $payment->created_by = 1;
            $payment->save();
        }

        return $pos;
    }

    public function test_table_closes_when_the_only_check_is_paid(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7, paid: true);

        $this->controller->runCloseCheck($table->id, 1, 7);

        $table->refresh();
        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->status);
        $this->assertSame(0, (int) $table->current_guests);
        $this->assertNull($table->customer_name);
        $this->assertNull($table->waiter_user_id);
    }

    public function test_table_stays_occupied_while_another_check_of_same_shift_is_unpaid(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7, paid: true);
        $this->check($table, 7); // second open check, same shift

        $this->controller->runCloseCheck($table->id, 1, 7);

        $this->assertSame(PosTable::STATUS_OCCUPIED, $table->fresh()->status);
    }

    public function test_stale_unpaid_check_from_previous_shift_does_not_block_closing(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 6); // abandoned yesterday, never paid, never cancelled
        $this->check($table, 7, paid: true);

        $this->controller->runCloseCheck($table->id, 1, 7);

        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->fresh()->status);
    }

    public function test_check_with_all_lines_voided_does_not_block_closing(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7, withItems: false); // lines voided -> empty check
        $this->check($table, 7, paid: true);

        $this->controller->runCloseCheck($table->id, 1, 7);

        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->fresh()->status);
    }

    public function test_unpaid_check_keeps_table_occupied(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7); // nothing paid yet (payment failed / pending)

        $this->controller->runCloseCheck($table->id, 1, 7);

        $this->assertSame(PosTable::STATUS_OCCUPIED, $table->fresh()->status);
    }
}
