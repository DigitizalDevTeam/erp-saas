<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use App\Models\ProductService;
use App\Models\ProductWarehouseStockAlert;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PosShiftReportService;
use App\Services\ProductStockStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Per-warehouse low / max stock alerts (Action-column popup):
 * different low/max per warehouse for one product, falling back to
 * the product's own thresholds, then to the system default.
 */
class WarehouseStockAlertTest extends TestCase
{
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
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->timestamps();
        });
        Schema::create('settings', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('warehouses', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_service_categories', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_warehouse_stock_alerts', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });
        Schema::create('product_option_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('parent_product_id')->nullable();
            $table->integer('option_product_id')->nullable();
            $table->string('option_group')->nullable();
            $table->integer('is_required')->default(0);
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('unit')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->integer('ingredient_product_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'edit product & service', 'guard_name' => 'web']);
        $user->givePermissionTo('edit product & service');
    }

    private function makeProduct(int $low = 20, int $max = 100): ProductService
    {
        $p = new ProductService();
        $p->name = 'Sugar';
        $p->sku = 'SUG-1';
        $p->type = 'product';
        $p->low_stock_threshold = $low;
        $p->max_stock_threshold = $max;
        $p->affect_stock = 1;
        $p->created_by = 1;
        $p->save();

        return $p;
    }

    private function makeWarehouse(string $name, int $creator = 1): warehouse
    {
        $w = new warehouse();
        $w->name = $name;
        $w->created_by = $creator;
        $w->save();

        return $w;
    }

    public function test_popup_lists_all_warehouses_with_saved_values_and_stock(): void
    {
        $product = $this->makeProduct();
        $bar = $this->makeWarehouse('BAR');
        $kitchen = $this->makeWarehouse('COZINHA');

        WarehouseProduct::create(['warehouse_id' => $bar->id, 'product_id' => $product->id, 'quantity' => 8, 'created_by' => 1]);
        ProductWarehouseStockAlert::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'low_stock_threshold' => 5, 'max_stock_threshold' => 50, 'created_by' => 1]);

        $response = (new ProductServiceController())->warehouseStockAlerts($product->id);
        $data = $response->getData(true);

        $this->assertSame('Sugar', $data['product']['name']);
        $this->assertSame(20, $data['product']['low_stock_threshold']);
        $this->assertCount(2, $data['warehouses']);
        $this->assertEquals(8, $data['warehouses'][0]['current_quantity']);
        $this->assertSame(5, $data['warehouses'][0]['low_stock_threshold']);
        $this->assertSame(50, $data['warehouses'][0]['max_stock_threshold']);
        // Kitchen has no saved alert: inputs stay empty (fallback applies).
        $this->assertNull($data['warehouses'][1]['low_stock_threshold']);
        $this->assertNull($data['warehouses'][1]['max_stock_threshold']);
    }

    public function test_save_stores_different_values_per_warehouse_and_clears_empty_rows(): void
    {
        $product = $this->makeProduct();
        $bar = $this->makeWarehouse('BAR');
        $kitchen = $this->makeWarehouse('COZINHA');
        ProductWarehouseStockAlert::create(['product_id' => $product->id, 'warehouse_id' => $kitchen->id, 'low_stock_threshold' => 9, 'max_stock_threshold' => 90, 'created_by' => 1]);

        $request = new Request([
            'warehouses' => [
                ['warehouse_id' => $bar->id, 'low_stock_threshold' => 3, 'max_stock_threshold' => 30],
                // Both empty: the old Kitchen row must be deleted (fallback).
                ['warehouse_id' => $kitchen->id, 'low_stock_threshold' => '', 'max_stock_threshold' => null],
                // Warehouse of another company: silently ignored.
                ['warehouse_id' => 999, 'low_stock_threshold' => 1, 'max_stock_threshold' => 1],
            ],
        ]);
        $response = (new ProductServiceController())->warehouseStockAlertsStore($product->id, $request);
        $data = $response->getData(true);

        $this->assertSame(1, $data['saved']);
        $this->assertDatabaseHas('product_warehouse_stock_alerts', [
            'product_id' => $product->id, 'warehouse_id' => $bar->id,
            'low_stock_threshold' => 3, 'max_stock_threshold' => 30,
        ]);
        $this->assertDatabaseMissing('product_warehouse_stock_alerts', [
            'product_id' => $product->id, 'warehouse_id' => $kitchen->id,
        ]);
        $this->assertDatabaseMissing('product_warehouse_stock_alerts', [
            'product_id' => $product->id, 'warehouse_id' => 999,
        ]);
    }

    public function test_stock_status_uses_per_warehouse_low_with_product_fallback(): void
    {
        $product = $this->makeProduct(20, 100);
        $bar = $this->makeWarehouse('BAR');
        $kitchen = $this->makeWarehouse('COZINHA');
        ProductWarehouseStockAlert::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'low_stock_threshold' => 5, 'max_stock_threshold' => null, 'created_by' => 1]);

        $chart = app(ProductStockStatusService::class)->chartFor($product, 1);
        $row = $chart['product_stock'];

        $this->assertSame(5, $row['low_stock_thresholds'][$bar->id]);
        $this->assertSame(20, $row['low_stock_thresholds'][$kitchen->id]);
        $this->assertSame(20, $row['low_stock_threshold']);
    }

    public function test_pos_shift_alert_uses_per_warehouse_low_before_product_low(): void
    {
        $product = $this->makeProduct(20, 100);
        $bar = $this->makeWarehouse('BAR');
        $kitchen = $this->makeWarehouse('COZINHA');
        // BAR qty 8: below product low (20) but above warehouse low (5) -> NOT an alert.
        WarehouseProduct::create(['warehouse_id' => $bar->id, 'product_id' => $product->id, 'quantity' => 8, 'created_by' => 1]);
        ProductWarehouseStockAlert::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'low_stock_threshold' => 5, 'max_stock_threshold' => null, 'created_by' => 1]);
        // COZINHA qty 8: no warehouse alert -> product low (20) applies -> IS an alert.
        WarehouseProduct::create(['warehouse_id' => $kitchen->id, 'product_id' => $product->id, 'quantity' => 8, 'created_by' => 1]);

        $result = (new PosShiftReportService())->minimumStockAlertRows(1);
        $rows = $result['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($kitchen->id, (int) $rows[0]->warehouse_id);
        $this->assertSame(20, (int) $rows[0]->low_stock_threshold);
    }

    public function test_action_button_markup_is_present_in_list_view(): void
    {
        $blade = file_get_contents(base_path('resources/views/productservice/index.blade.php'));

        $this->assertStringContainsString('warehouseStockAlertModal', $blade);
        $this->assertStringContainsString('warehouse-stock-alert-btn', $blade);
        $this->assertStringContainsString('warehouse-stock-alerts', $blade);

        // Regression guard (2026-10-24): the popup JS was first pushed to
        // @push('scripts'), a stack the admin layout never yields, so the
        // button rendered but the click did nothing. It must live in a
        // @push('script-page') block, which the admin footer yields.
        preg_match_all("/@push\\('script-page'\\)(.*?)@endpush/s", $blade, $matches);
        $scriptPageBlocks = $matches[1] ?? [];
        $this->assertNotEmpty($scriptPageBlocks);
        $found = false;
        foreach ($scriptPageBlocks as $block) {
            if (str_contains($block, 'warehouse-stock-alert-btn')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Warehouse alert JS must be inside a script-page push block.');

        $controller = file_get_contents(base_path('app/Http/Controllers/ProductServiceController.php'));
        $this->assertStringContainsString('warehouse-stock-alert-btn', $controller);
    }
}
