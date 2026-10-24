<?php

namespace Tests\Unit;

use App\Models\ProductService;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\InvoiceSaleDeductionService;
use App\Services\PosRecipeDeductionService;
use App\Services\PosWarehouseAvailabilityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Allow negative stock (per-product toggle): flagged products may be
 * sold below zero (warehouse row + global quantity go negative, ledger
 * row still written); unflagged products keep the old clamp at zero.
 */
class AllowNegativeStockTest extends TestCase
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
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
            $table->integer('variants_enabled')->default(0);
            $table->integer('options_enabled')->default(0);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
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
        Schema::create('stock_reports', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->string('description')->nullable();
            $table->integer('created_by');
            $table->timestamps();
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
        Schema::create('product_expiry_lots', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity_remaining', 12, 4)->default(0);
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
    }

    private function makeProduct(string $name, float $qty, bool $allowNegative = false, string $type = 'product'): ProductService
    {
        $p = new ProductService();
        $p->name = $name;
        $p->sku = strtoupper(substr($name, 0, 3)).'-1';
        $p->type = $type;
        $p->quantity = $qty;
        $p->affect_stock = 1;
        $p->allow_negative_stock = $allowNegative;
        $p->created_by = 1;
        $p->save();

        return $p;
    }

    private function makeWarehouse(string $name): warehouse
    {
        $w = new warehouse();
        $w->name = $name;
        $w->created_by = 1;
        $w->save();

        return $w;
    }

    private function stockIn(int $productId, int $warehouseId): ?float
    {
        $row = WarehouseProduct::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();

        return $row ? (float) $row->quantity : null;
    }

    public function test_warehouse_minus_clamps_unflagged_and_goes_negative_when_flagged(): void
    {
        $plain = $this->makeProduct('Plain', 5);
        $flagged = $this->makeProduct('Flagged', 5, true);
        $w = $this->makeWarehouse('BAR');
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $plain->id, 'quantity' => 5, 'created_by' => 1]);
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $flagged->id, 'quantity' => 5, 'created_by' => 1]);

        Utility::warehouse_quantity('minus', 8, $plain->id, $w->id);
        Utility::warehouse_quantity('minus', 8, $flagged->id, $w->id);

        $this->assertSame(0.0, $this->stockIn($plain->id, $w->id));
        $this->assertSame(-3.0, $this->stockIn($flagged->id, $w->id));
    }

    public function test_pos_deduction_goes_negative_only_when_flagged(): void
    {
        $plain = $this->makeProduct('Plain', 5);
        $flagged = $this->makeProduct('Flagged', 5, true);
        $w = $this->makeWarehouse('BAR');
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $plain->id, 'quantity' => 5, 'created_by' => 1]);
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $flagged->id, 'quantity' => 5, 'created_by' => 1]);

        PosRecipeDeductionService::deductForSale($plain->id, 8, $w->id, 1, 'PDV00001', 1);
        PosRecipeDeductionService::deductForSale($flagged->id, 8, $w->id, 1, 'PDV00002', 2);

        $this->assertSame(0.0, (float) $plain->fresh()->quantity);
        $this->assertSame(0.0, $this->stockIn($plain->id, $w->id));
        $this->assertSame(-3.0, (float) $flagged->fresh()->quantity);
        $this->assertSame(-3.0, $this->stockIn($flagged->id, $w->id));
        // Ledger rows are still written for both sales.
        $this->assertDatabaseHas('stock_reports', ['product_id' => $flagged->id, 'type' => 'pos']);
    }

    public function test_availability_is_unlimited_only_when_flagged(): void
    {
        $plain = $this->makeProduct('Plain', 0);
        $flagged = $this->makeProduct('Flagged', 0, true);
        $w = $this->makeWarehouse('BAR');
        $service = app(PosWarehouseAvailabilityService::class);

        $this->assertSame(0, $service->maxSellableQty($plain, $w->id, null, [], 1));
        $this->assertSame(999999, $service->maxSellableQty($flagged, $w->id, null, [], 1));
    }

    public function test_batch_tracked_product_never_qualifies_even_when_flagged(): void
    {
        $p = $this->makeProduct('Batched', 5, true);
        $p->batch_tracking = 1;
        $p->save();

        $this->assertFalse($p->fresh()->allowsNegativeStock());
    }

    public function test_invoice_deduction_goes_negative_only_when_flagged(): void
    {
        $plain = $this->makeProduct('Plain', 5);
        $flagged = $this->makeProduct('Flagged', 5, true);

        InvoiceSaleDeductionService::deductLine($plain->id, 8, 1, 1, 1, 1, 'INV00001');
        InvoiceSaleDeductionService::deductLine($flagged->id, 8, 1, 2, 2, 1, 'INV00002');

        $this->assertSame(0.0, (float) $plain->fresh()->quantity);
        $this->assertSame(-3.0, (float) $flagged->fresh()->quantity);
    }

    public function test_form_toggle_present_and_controller_forces_off_for_kit_and_service(): void
    {
        $create = file_get_contents(base_path('resources/views/productservice/create.blade.php'));
        $edit = file_get_contents(base_path('resources/views/productservice/edit.blade.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/ProductServiceController.php'));

        $this->assertStringContainsString('allow_negative_stock', $create);
        $this->assertStringContainsString('allow-negative-stock-option', $create);
        $this->assertStringContainsString('allow_negative_stock', $edit);
        $this->assertStringContainsString('allow-negative-stock-option', $edit);
        $this->assertStringContainsString("in_array(\$request->type, ['kit', 'service'], true)", $controller);
    }
}
