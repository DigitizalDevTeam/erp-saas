<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PosLineStockRestoreService;
use App\Services\PosRecipeDeductionService;
use App\Services\PosWarehouseAvailabilityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Logical audit of the stock-flag rules, end to end:
 * - "Does not affect stock" items are never checked, never deducted,
 *   never restored — for options AND recipe ingredients alike.
 * - Negative-allowed items never block; their stock simply goes below
 *   zero (global and warehouse).
 * - Unflagged, stock-affecting items still limit/block and clamp at 0.
 */
class StockFlagLogicAuditTest extends TestCase
{
    private int $warehouseId;

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
        Schema::create('pos', function ($table) {
            $table->bigIncrements('id');
            $table->integer('warehouse_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->text('selected_options_json')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        $this->warehouseId = (int) $w->id;
    }

    private function product(string $type, int $affect, int $allowNegative, float $qty): ProductService
    {
        $p = new ProductService();
        $p->name = 'Item ' . uniqid();
        $p->sku = 'SKU-' . uniqid();
        $p->type = $type;
        $p->quantity = $qty;
        $p->affect_stock = $affect;
        $p->allow_negative_stock = $allowNegative;
        $p->created_by = 1;
        $p->save();
        WarehouseProduct::create([
            'warehouse_id' => $this->warehouseId,
            'product_id' => $p->id,
            'quantity' => $qty,
            'created_by' => 1,
        ]);

        return $p;
    }

    private function recipe(int $parentId, int $ingredientId, float $qty): void
    {
        DB::table('product_recipes')->insert([
            'product_id' => $parentId,
            'ingredient_product_id' => $ingredientId,
            'quantity' => $qty,
            'created_by' => 1,
        ]);
    }

    private function warehouseQty(int $productId): float
    {
        return (float) (WarehouseProduct::where('product_id', $productId)->value('quantity') ?? 0);
    }

    public function test_no_affect_ingredient_is_not_checked_but_unflagged_one_limits(): void
    {
        $parent = $this->product('product', 1, 0, 0);
        $noAffect = $this->product('ingredient', 0, 0, 0);
        $limited = $this->product('ingredient', 1, 0, 10);
        $this->recipe($parent->id, $noAffect->id, 5);
        $this->recipe($parent->id, $limited->id, 2);

        $sellable = app(PosWarehouseAvailabilityService::class)->maxSellableQty(
            $parent, $this->warehouseId, null, [], 1
        );

        // No-affect ingredient ignored; unflagged one caps at 10 / 2 = 5.
        $this->assertSame(5, $sellable);
    }

    public function test_recipe_deduction_skips_no_affect_goes_negative_for_flagged_clamps_unflagged(): void
    {
        $parent = $this->product('product', 1, 0, 0);
        $noAffect = $this->product('ingredient', 0, 0, 7);
        $flagged = $this->product('ingredient', 1, 1, 0);
        $clamped = $this->product('ingredient', 1, 0, 1);
        $this->recipe($parent->id, $noAffect->id, 3);
        $this->recipe($parent->id, $flagged->id, 1);
        $this->recipe($parent->id, $clamped->id, 1);

        PosRecipeDeductionService::deductForSale($parent->id, 2, $this->warehouseId, 1, '#PDV00001', 1);

        $this->assertSame(7.0, $this->warehouseQty($noAffect->id));
        $this->assertSame(7.0, (float) $noAffect->fresh()->quantity);
        $this->assertSame(-2.0, $this->warehouseQty($flagged->id));
        $this->assertSame(-2.0, (float) $flagged->fresh()->quantity);
        $this->assertSame(0.0, $this->warehouseQty($clamped->id));
        $this->assertSame(0.0, (float) $clamped->fresh()->quantity);
    }

    public function test_void_restore_skips_no_affect_but_returns_flagged(): void
    {
        $parent = $this->product('product', 1, 0, 0);
        $noAffect = $this->product('ingredient', 0, 0, 7);
        $flagged = $this->product('ingredient', 1, 1, -2);
        $this->recipe($parent->id, $noAffect->id, 3);
        $this->recipe($parent->id, $flagged->id, 1);

        $pos = new Pos();
        $pos->warehouse_id = $this->warehouseId;
        $pos->save();
        $line = new PosProduct();
        $line->product_id = $parent->id;
        $line->warehouse_id = $this->warehouseId;
        $line->quantity = 2;
        $line->stock_deducted_at = now();
        $line->save();

        $this->assertTrue(PosLineStockRestoreService::restoreLine($pos, $line, 1, '#PDV00001'));

        $this->assertSame(7.0, $this->warehouseQty($noAffect->id));
        $this->assertSame(0.0, $this->warehouseQty($flagged->id));
    }

    public function test_ingredient_form_no_longer_forces_affect_stock(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/ProductServiceController.php'));
        $this->assertStringNotContainsString("'ingredient' => true", $controller);

        $create = file_get_contents(base_path('resources/views/productservice/create.blade.php'));
        $edit = file_get_contents(base_path('resources/views/productservice/edit.blade.php'));
        $this->assertStringContainsString("$('.product-stock-option').removeClass('d-none');", $create);
        $this->assertStringContainsString("$('.product-stock-option').removeClass('d-none');", $edit);
        $this->assertStringNotContainsString("['service','kit','ingredient']", $edit);
    }
}
