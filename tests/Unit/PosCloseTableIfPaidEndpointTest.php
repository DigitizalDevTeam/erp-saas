<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\PosTable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The AJAX endpoint the restaurant screen calls right after a successful
 * payment: it must report (and set) the table free only when every payable
 * check of the shift is paid.
 */
class PosCloseTableIfPaidEndpointTest extends TestCase
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

        $user = new class extends User
        {
            public function canManagePos(): bool
            {
                return true;
            }

            public function creatorId()
            {
                return 1;
            }
        };
        $user->id = 1;
        $user->type = 'company';
        Auth::setUser($user);

        $this->controller = new PosController();
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
        $table->name = '03';
        $table->status = PosTable::STATUS_OCCUPIED;
        $table->current_guests = 2;
        $table->customer_name = 'Walk-in';
        $table->waiter_user_id = 5;
        $table->created_by = 1;
        $table->save();

        return $table;
    }

    private function check(PosTable $table, int $shiftId, bool $paid): Pos
    {
        $pos = new Pos();
        $pos->pos_id = 'PDV' . random_int(10000, 99999);
        $pos->table_id = $table->id;
        $pos->shift_id = $shiftId;
        $pos->created_by = 1;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = 10;
        $line->save();

        if ($paid) {
            $payment = new PosPayment();
            $payment->pos_id = $pos->id;
            $payment->amount = 100;
            $payment->created_by = 1;
            $payment->save();
        }

        return $pos;
    }

    private function callEndpoint(PosTable $table, int $shiftId)
    {
        $request = Request::create('/', 'POST', [
            'pos_table_id' => $table->id,
            'pos_shift_id' => $shiftId,
        ]);

        return $this->controller->closeTableIfPaid($request);
    }

    public function test_endpoint_closes_and_reports_available_after_full_payment(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7, paid: true);

        $response = $this->callEndpoint($table, 7);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertTrue($data['closed']);
        $this->assertSame(PosTable::STATUS_AVAILABLE, $data['status']);
        $this->assertSame(PosTable::STATUS_AVAILABLE, $table->fresh()->status);
    }

    public function test_endpoint_reports_occupied_while_another_check_is_unpaid(): void
    {
        $table = $this->occupiedTable();
        $this->check($table, 7, paid: true);
        $this->check($table, 7, paid: false);

        $response = $this->callEndpoint($table, 7);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertFalse($data['closed']);
        $this->assertSame(PosTable::STATUS_OCCUPIED, $data['status']);
        $this->assertSame(PosTable::STATUS_OCCUPIED, $table->fresh()->status);
    }

    public function test_endpoint_404_for_unknown_table(): void
    {
        $table = $this->occupiedTable();
        $request = Request::create('/', 'POST', [
            'pos_table_id' => $table->id + 999,
            'pos_shift_id' => 7,
        ]);

        $response = $this->controller->closeTableIfPaid($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
