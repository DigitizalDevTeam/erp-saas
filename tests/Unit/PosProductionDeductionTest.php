<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\WarehouseProduct;
use App\Services\PosLineStockDeductionService;
use App\Services\PosLineStockRestoreService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Restaurant POS: stock is deducted when a line is sent to production
 * (PosLineStockDeductionService), payment must not deduct it again, and
 * voiding/cancelling the line returns the stock
 * (PosLineStockRestoreService).
 */
class PosProductionDeductionTest extends TestCase
{
    private int $creatorId;

    private int $warehouseId = 7;

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
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->boolean('affect_stock')->default(true);
            $table->boolean('options_enabled')->default(false);
            $table->boolean('variants_enabled')->default(false);
            $table->boolean('batch_tracking')->default(false);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity_remaining', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->string('document_type')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('unit_subtotal', 15, 4)->nullable();
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 4)->default(0);
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'POS Tester';
        $user->email = 'pos-tester@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);
        $this->creatorId = (int) $user->creatorId();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('product_expiry_lots');
        Schema::dropIfExists('stock_reports');
        Schema::dropIfExists('warehouse_products');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function makeProduct(string $name, string $type, float $quantity, array $extra = []): ProductService
    {
        $product = new ProductService();
        $product->name = $name;
        $product->sku = 'SKU-' . $name;
        $product->type = $type;
        $product->quantity = $quantity;
        $product->affect_stock = $extra['affect_stock'] ?? true;
        $product->options_enabled = $extra['options_enabled'] ?? false;
        $product->created_by = $this->creatorId;
        $product->save();

        if (isset($extra['warehouse_qty'])) {
            WarehouseProduct::create([
                'warehouse_id' => $this->warehouseId,
                'product_id' => $product->id,
                'quantity' => $extra['warehouse_qty'],
                'created_by' => $this->creatorId,
            ]);
        }

        return $product;
    }

    private function makePos(): Pos
    {
        $pos = new Pos();
        $pos->pos_id = 77;
        $pos->document_type = 'cash_sale';
        $pos->warehouse_id = $this->warehouseId;
        $pos->created_by = $this->creatorId;
        $pos->save();

        return $pos;
    }

    private function makeLine(Pos $pos, ProductService $product, float $qty, array $extra = []): PosProduct
    {
        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $product->id;
        $line->quantity = $qty;
        $line->price = 100;
        $line->status = PosProduct::STATUS_PENDING;
        $line->sent_at = now();
        $line->selected_options_json = $extra['selected_options_json'] ?? null;
        $line->warehouse_id = $extra['warehouse_id'] ?? null;
        $line->save();

        return $line;
    }

    private function globalQty(int $productId): float
    {
        return (float) ProductService::find($productId)->quantity;
    }

    private function warehouseQty(int $productId): float
    {
        return (float) (WarehouseProduct::where('product_id', $productId)
            ->where('warehouse_id', $this->warehouseId)
            ->value('quantity') ?? 0);
    }

    public function test_production_deduction_moves_stock_and_flags_line(): void
    {
        $pos = $this->makePos();
        $product = $this->makeProduct('Juice', 'product', 100, ['warehouse_qty' => 100]);
        $line = $this->makeLine($pos, $product, 3);

        $done = PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00077');

        $this->assertTrue($done);
        $this->assertEqualsWithDelta(97.0, $this->globalQty($product->id), 0.001);
        $this->assertEqualsWithDelta(97.0, $this->warehouseQty($product->id), 0.001);
        $this->assertNotNull($line->fresh()->stock_deducted_at);

        $row = StockReport::where('type', 'pos')->where('product_id', $product->id)->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(3.0, (float) $row->quantity, 0.001);
        $this->assertStringContainsString('3 quantity sold in pos PDV00077', (string) $row->description);
    }

    public function test_payment_time_second_call_does_not_double_deduct(): void
    {
        $pos = $this->makePos();
        $product = $this->makeProduct('Juice', 'product', 100, ['warehouse_qty' => 100]);
        $line = $this->makeLine($pos, $product, 3);

        PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00077');
        // Payment later calls the same service; the flag must make it a no-op.
        $again = PosLineStockDeductionService::deductLine($pos, $line->fresh(), $this->creatorId, 'PDV00077');

        $this->assertFalse($again);
        $this->assertEqualsWithDelta(97.0, $this->globalQty($product->id), 0.001);
        $this->assertEqualsWithDelta(97.0, $this->warehouseQty($product->id), 0.001);
        $this->assertSame(1, StockReport::where('type', 'pos')->where('product_id', $product->id)->count());
    }

    public function test_production_deduction_uses_option_setup_qty(): void
    {
        $pos = $this->makePos();
        $option = $this->makeProduct('Capsula12', 'product', 500, ['warehouse_qty' => 500]);
        $parent = $this->makeProduct('CafeLatte', 'product', 0, [
            'options_enabled' => true,
            'affect_stock' => false,
        ]);
        $line = $this->makeLine($pos, $parent, 1, [
            'selected_options_json' => json_encode([
                ['product_id' => $option->id, 'quantity' => 50, 'price' => 0],
            ]),
        ]);

        $done = PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00077');

        $this->assertTrue($done);
        $this->assertEqualsWithDelta(450.0, $this->globalQty($option->id), 0.001);
        $this->assertEqualsWithDelta(450.0, $this->warehouseQty($option->id), 0.001);
        $row = StockReport::where('type', 'pos')->where('product_id', $option->id)->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('50 quantity sold in pos PDV00077', (string) $row->description);
    }

    public function test_void_restore_returns_stock_with_reversal_row(): void
    {
        $pos = $this->makePos();
        $product = $this->makeProduct('Juice', 'product', 100, ['warehouse_qty' => 100]);
        $line = $this->makeLine($pos, $product, 3);

        PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00077');
        $restored = PosLineStockRestoreService::restoreLine($pos, $line->fresh(), $this->creatorId, 'PDV00077');

        $this->assertTrue($restored);
        $this->assertEqualsWithDelta(100.0, $this->globalQty($product->id), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->warehouseQty($product->id), 0.001);
        $this->assertNotNull($line->fresh()->stock_restored_at);

        $rows = StockReport::where('type', 'pos')->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(-3.0, (float) $rows[1]->quantity, 0.001);
        $this->assertStringContainsString('returned', (string) $rows[1]->description);

        // A second restore (e.g. order cancel after line void) must be a no-op.
        $again = PosLineStockRestoreService::restoreLine($pos, $line->fresh(), $this->creatorId, 'PDV00077');
        $this->assertFalse($again);
        $this->assertEqualsWithDelta(100.0, $this->globalQty($product->id), 0.001);
    }

    public function test_recipe_ingredients_deduct_at_production_and_return_on_void(): void
    {
        $pos = $this->makePos();
        $ingredient = $this->makeProduct('CoffeePowder', 'ingredient', 100, ['warehouse_qty' => 100]);
        $parent = $this->makeProduct('Coffee', 'product', 0, ['affect_stock' => false]);
        ProductRecipe::create([
            'product_id' => $parent->id,
            'ingredient_product_id' => $ingredient->id,
            'quantity' => 2,
            'created_by' => $this->creatorId,
        ]);
        $line = $this->makeLine($pos, $parent, 3);

        PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00077');
        $this->assertEqualsWithDelta(94.0, $this->globalQty($ingredient->id), 0.001);
        $this->assertEqualsWithDelta(94.0, $this->warehouseQty($ingredient->id), 0.001);

        PosLineStockRestoreService::restoreLine($pos, $line->fresh(), $this->creatorId, 'PDV00077');
        $this->assertEqualsWithDelta(100.0, $this->globalQty($ingredient->id), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->warehouseQty($ingredient->id), 0.001);
    }

    public function test_restore_without_production_deduction_is_noop(): void
    {
        $pos = $this->makePos();
        $product = $this->makeProduct('Juice', 'product', 100, ['warehouse_qty' => 100]);
        $line = $this->makeLine($pos, $product, 3);

        $restored = PosLineStockRestoreService::restoreLine($pos, $line, $this->creatorId, 'PDV00077');

        $this->assertFalse($restored);
        $this->assertEqualsWithDelta(100.0, $this->globalQty($product->id), 0.001);
        $this->assertSame(0, StockReport::where('type', 'pos')->count());
    }
}
