<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductReportsController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockMovementPosSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('warehouse_transfers', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->default(0);
            $table->integer('from_warehouse')->nullable();
            $table->integer('to_warehouse')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('warehouse_losses', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->default(0);
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->date('date')->nullable();
            $table->string('reason')->nullable();
            $table->string('write_off_no')->nullable();
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
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        $user = new User();
        $user->id = 1;
        $user->type = 'super admin';
        Auth::setUser($user);

        \DB::table('product_services')->insert([
            'id' => 10, 'sku' => 'OPT-40', 'name' => 'Extra Shot', 'description' => 'option',
            'quantity' => 500, 'created_by' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['stock_reports', 'warehouse_products', 'warehouse_losses', 'warehouse_transfers', 'warehouses', 'product_services'] as $t) {
            Schema::dropIfExists($t);
        }
        Auth::logout();
        parent::tearDown();
    }

    private function rows(array $query = []): \Illuminate\Support\Collection
    {
        $controller = new ProductReportsController();
        $method = new \ReflectionMethod(ProductReportsController::class, 'stockMovementData');
        $method->setAccessible(true);

        return $method->invoke($controller, new Request($query))['rows'];
    }

    public function test_pos_sale_appears_with_actual_moved_qty(): void
    {
        \DB::table('stock_reports')->insert([
            'product_id' => 10, 'quantity' => 40, 'type' => 'pos', 'type_id' => 7,
            'description' => '40 quantity sold in pos POS-7', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $pos = $rows->first();
        $this->assertSame('pos', $pos->transaction_kind);
        $this->assertSame(10, $pos->product_id);
        // Stock-out movement carries the actual deducted qty (40 -> -40).
        $this->assertEquals(-40, $pos->movement);
        $this->assertNotNull($pos->sort_date);
        $this->assertSame('40 quantity sold in pos POS-7', $pos->reason);
    }

    public function test_pos_source_filter_excludes_transfers(): void
    {
        \DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Main', 'created_by' => 1],
            ['id' => 2, 'name' => 'Branch', 'created_by' => 1],
        ]);
        \DB::table('warehouse_transfers')->insert([
            'product_id' => 10, 'from_warehouse' => 1, 'to_warehouse' => 2,
            'quantity' => 5, 'date' => now()->format('Y-m-d'), 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('stock_reports')->insert([
            'product_id' => 10, 'quantity' => 40, 'type' => 'pos', 'type_id' => 7,
            'description' => '40 quantity sold in pos POS-7', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->rows(['source' => 'pos']);

        $this->assertCount(1, $rows);
        $this->assertSame('pos', $rows->first()->transaction_kind);
    }

    public function test_pos_sale_outside_date_range_is_excluded(): void
    {
        \DB::table('stock_reports')->insert([
            'product_id' => 10, 'quantity' => 40, 'type' => 'pos', 'type_id' => 7,
            'description' => 'old sale', 'created_by' => 1,
            'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60),
        ]);

        $this->assertCount(0, $this->rows());
    }
}
