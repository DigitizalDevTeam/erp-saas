<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Utility;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseDeductionFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->default('product');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->boolean('affect_stock')->default(true);
            $table->boolean('options_enabled')->default(false);
            $table->integer('created_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->default(0);
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        $user = new User();
        $user->id = 1;
        $user->type = 'super admin';
        Auth::setUser($user);
    }

    protected function tearDown(): void
    {
        foreach (['stock_reports', 'warehouse_products', 'product_services'] as $t) {
            Schema::dropIfExists($t);
        }
        Auth::logout();
        parent::tearDown();
    }

    public function test_option_deduction_reason_uses_actual_option_qty(): void
    {
        \DB::table('product_services')->insert([
            'id' => 20, 'sku' => 'OPT-50', 'name' => 'Option 50', 'type' => 'product',
            'quantity' => 500, 'affect_stock' => 1, 'options_enabled' => 0, 'created_by' => 1,
        ]);

        // Option qty 50 is per parent unit: selling 2 parents moves 100, and
        // the ledger description must carry that actual total (100), not the
        // bare setup qty (50) or the bare line qty (2).
        ProductOptionService::deductOptionsForSale(
            [['product_id' => 20, 'quantity' => 50]],
            2.0,
            0,
            1,
            'pos',
            'PDV00055',
            7
        );

        $row = \DB::table('stock_reports')->where('product_id', 20)->first();
        $this->assertNotNull($row);
        $this->assertSame('pos', $row->type);
        $this->assertSame(100, (int) $row->quantity);
        $this->assertStringStartsWith('100 ', $row->description);
        $this->assertStringContainsString('PDV00055', $row->description);
        $this->assertStringNotContainsString('2 quantity sold', $row->description);
    }

    public function test_warehouse_minus_creates_missing_row_instead_of_silent_skip(): void
    {
        // No warehouse_products row exists for warehouse 52 / product 20.
        Utility::warehouse_quantity('minus', 100, 20, 52);

        $row = \DB::table('warehouse_products')
            ->where('warehouse_id', 52)
            ->where('product_id', 20)
            ->first();

        // The deduction is recorded (clamped at zero) instead of silently lost.
        $this->assertNotNull($row);
        $this->assertEquals(0, (float) $row->quantity);
    }

    public function test_warehouse_minus_deducts_existing_row(): void
    {
        \DB::table('warehouse_products')->insert([
            'warehouse_id' => 52, 'product_id' => 20, 'product_variant_id' => null,
            'quantity' => 500, 'created_by' => 1,
        ]);

        Utility::warehouse_quantity('minus', 100, 20, 52);

        $row = \DB::table('warehouse_products')
            ->where('warehouse_id', 52)
            ->where('product_id', 20)
            ->first();

        $this->assertEquals(400, (float) $row->quantity);
    }
}
