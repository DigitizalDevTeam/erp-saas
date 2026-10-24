<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Stock Status popup "Transfer" (POST productservice/{id}/stock-transfer):
 * one popup row (product, option or recipe ingredient) moves between
 * two warehouses of the same company, recorded exactly like a
 * /warehouse-transfer document (WarehouseTransfer row + transfer_out /
 * transfer_in ledger rows), with the same guards: From != To, quantity
 * cannot exceed source stock, and the item must be a row of this popup.
 */
class StockStatusTransferTest extends TestCase
{
    private int $creatorId = 1;
    private warehouse $bar;
    private warehouse $bebidas;
    private ProductService $product;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedInteger('role_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedInteger('permission_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->boolean('affect_stock')->default(true);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_transfers', function ($table) {
            $table->increments('id');
            $table->string('transfer_batch_id')->nullable();
            $table->integer('from_warehouse')->default(0);
            $table->integer('to_warehouse')->default(0);
            $table->integer('product_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->string('type');
            $table->integer('type_id')->default(0);
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('product_option_items', function ($table) {
            $table->increments('id');
            $table->integer('parent_product_id')->nullable();
            $table->integer('option_product_id')->nullable();
            $table->string('option_group')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->integer('ingredient_product_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->id = $this->creatorId;
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->created_by = $this->creatorId;
        $user->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage product & service', 'create warehouse'] as $name) {
            $user->givePermissionTo(Permission::create(['name' => $name, 'guard_name' => 'web']));
        }
        Auth::login($user);

        $this->bar = warehouse::create(['name' => 'Armazém | BAR', 'created_by' => $this->creatorId]);
        $this->bebidas = warehouse::create(['name' => 'BEBIDAS', 'created_by' => $this->creatorId]);
        $this->product = ProductService::create([
            'name' => 'Café Latte', 'sku' => '0000123', 'type' => 'product',
            'affect_stock' => true, 'created_by' => $this->creatorId,
        ]);
        WarehouseProduct::create([
            'product_id' => $this->product->id, 'warehouse_id' => $this->bar->id,
            'quantity' => 100, 'created_by' => $this->creatorId,
        ]);
    }

    private function transfer(array $overrides = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/productservice/' . $this->product->id . '/stock-transfer', 'POST', array_merge([
            'item_id' => $this->product->id,
            'from_warehouse' => $this->bar->id,
            'to_warehouse' => $this->bebidas->id,
            'quantity' => 30,
            'date' => '2026-10-24',
        ], $overrides));
        $request->setUserResolver(fn () => Auth::user());

        return (new ProductServiceController())->stockTransfer($this->product->id, $request);
    }

    private function stockIn(int $warehouseId): float
    {
        return (float) WarehouseProduct::where('product_id', $this->product->id)
            ->where('warehouse_id', $warehouseId)->sum('quantity');
    }

    public function test_transfer_moves_stock_and_records_document(): void
    {
        $response = $this->transfer();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(70.0, $this->stockIn($this->bar->id));
        $this->assertSame(30.0, $this->stockIn($this->bebidas->id));
        $this->assertSame(1, WarehouseTransfer::count());
        $this->assertSame(['transfer_in', 'transfer_out'], \App\Models\StockReport::orderBy('id')->pluck('type')->sort()->values()->all());
    }

    public function test_transfer_refuses_more_than_source_stock(): void
    {
        $response = $this->transfer(['quantity' => 101]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(100.0, $this->stockIn($this->bar->id));
        $this->assertSame(0, WarehouseTransfer::count());
    }

    public function test_transfer_refuses_same_warehouse_and_foreign_item(): void
    {
        $this->assertSame(422, $this->transfer(['to_warehouse' => $this->bar->id])->getStatusCode());

        $other = ProductService::create([
            'name' => 'Other', 'sku' => '0000999', 'type' => 'product',
            'affect_stock' => true, 'created_by' => $this->creatorId,
        ]);
        $this->assertSame(404, $this->transfer(['item_id' => $other->id])->getStatusCode());
        $this->assertSame(0, WarehouseTransfer::count());
    }
}
