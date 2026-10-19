<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosDiscountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_payments', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->decimal('discount', 15, 4)->default(0);
            $table->decimal('amount', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        // Utility::getSetting() caches in a static; reset so each test is isolated.
        $prop = new \ReflectionProperty(Utility::class, 'getsettings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \DB::table('settings')->insert([
            'name' => 'pos_max_discount_percent',
            'value' => '10',
            'created_by' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    private function approvalResponse(float $discount, float $subtotal)
    {
        $controller = new PosController();
        $method = new \ReflectionMethod(PosController::class, 'discountManagerApprovalResponse');
        $method->setAccessible(true);

        return $method->invoke($controller, $discount, $subtotal, new Request());
    }

    private function checkSubtotal($rows): float
    {
        $controller = new PosController();
        $method = new \ReflectionMethod(PosController::class, 'posDiscountCheckSubtotal');
        $method->setAccessible(true);

        return $method->invoke($controller, $rows);
    }

    public function test_discount_check_subtotal_sums_rows(): void
    {
        $this->assertEquals(300.0, $this->checkSubtotal([
            ['subtotal' => 100],
            ['price' => 50, 'quantity' => 4], // fallback: price x qty
        ]));
        $this->assertEquals(0.0, $this->checkSubtotal([]));
        $this->assertEquals(0.0, $this->checkSubtotal(null));
    }

    public function test_discount_below_max_needs_no_approval(): void
    {
        // 5 on a 100 subtotal = 5% < 10% max.
        $this->assertNull($this->approvalResponse(5.0, 100.0));
    }

    public function test_discount_above_max_without_manager_is_rejected(): void
    {
        // 15 on a 100 subtotal = 15% > 10% max, no manager password supplied.
        $response = $this->approvalResponse(15.0, 100.0);
        $this->assertNotNull($response);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(403, $response->getData(true)['code']);
    }

    public function test_no_approval_check_without_discount_or_subtotal(): void
    {
        $this->assertNull($this->approvalResponse(0.0, 100.0));
        $this->assertNull($this->approvalResponse(50.0, 0.0));
    }

    public function test_pos_get_total_discount_counts_order_discount_once(): void
    {
        $pos = new Pos();
        $pos->created_by = 1;
        $pos->save();

        // The order discount is stored once, on the first payment.
        foreach ([20, 0, 0] as $discount) {
            $payment = new PosPayment();
            $payment->pos_id = $pos->id;
            $payment->discount = $discount;
            $payment->amount = 100;
            $payment->created_by = 1;
            $payment->save();
        }

        // Must be 20, not 20 x 3 payments (and not the per-line multiplied value).
        $this->assertEquals(20.0, $pos->fresh()->getTotalDiscount());
    }

    public function test_pos_get_total_discount_is_zero_without_payments(): void
    {
        $pos = new Pos();
        $pos->created_by = 1;
        $pos->save();

        $this->assertEquals(0.0, $pos->fresh()->getTotalDiscount());
    }
}
