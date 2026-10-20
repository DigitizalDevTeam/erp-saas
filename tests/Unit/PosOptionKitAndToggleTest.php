<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductKitItem;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Services\PosLineStockRestoreService;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Option stock rules (Riz 2026-10-21):
 * - A KIT chosen as an option must consume its components, scaled by
 *   setup qty x parent line qty — even though the kit itself always has
 *   affect_stock = false (kits hold no own stock). Previously the
 *   affect_stock skip ran first and kit options deducted NOTHING.
 * - A plain product option whose own "Does not affect stock" toggle is
 *   enabled moves no stock; with the toggle off it deducts scaled.
 * - Voids/purchases mirror the same rules for kit components.
 */
class PosOptionKitAndToggleTest extends TestCase
{
    private ProductService $kit;

    private ProductService $componentA;

    private ProductService $componentB;

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
        $user = new User();
        $user->name = 'POS Tester';
        $user->email = 'pos-tester@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->boolean('options_enabled')->default(false);
            $table->boolean('variants_enabled')->default(false);
            $table->boolean('affect_stock')->default(true);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
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
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->integer('ingredient_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_kit_items', function ($table) {
            $table->increments('id');
            $table->integer('kit_product_id')->nullable();
            $table->integer('component_product_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->string('pos_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('warehouse_id')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        // Kit option: components A x3, B x1 per kit unit. Kits always have
        // affect_stock = false (set by ProductServiceController store/update).
        $this->componentA = $this->makeProduct('Component A', 1000);
        $this->componentB = $this->makeProduct('Component B', 500);
        $this->kit = $this->makeProduct('Combo Kit', 0, ['type' => 'kit', 'affect_stock' => false]);

        foreach ([[$this->componentA, 3], [$this->componentB, 1]] as [$component, $qty]) {
            $item = new ProductKitItem();
            $item->kit_product_id = $this->kit->id;
            $item->component_product_id = $component->id;
            $item->quantity = $qty;
            $item->created_by = 1;
            $item->save();
        }

        foreach ([[$this->componentA, 1000], [$this->componentB, 500]] as [$component, $qty]) {
            \DB::table('warehouse_products')->insert([
                'product_id' => $component->id, 'warehouse_id' => 1, 'quantity' => $qty, 'created_by' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('product_kit_items');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('stock_reports');
        Schema::dropIfExists('warehouse_products');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function makeProduct(string $name, float $qty, array $extra = []): ProductService
    {
        $p = new ProductService();
        $p->name = $name;
        $p->sku = 'SKU-' . $name;
        $p->type = 'product';
        $p->quantity = $qty;
        $p->sale_price = 100;
        $p->affect_stock = true;
        $p->created_by = 1;
        foreach ($extra as $k => $v) {
            $p->$k = $v;
        }
        $p->save();

        return $p;
    }

    /** Kit option selected at setup qty 2 on a line of 4 parents => 8 kit units. */
    private function kitSelected(): array
    {
        return [['product_id' => $this->kit->id, 'quantity' => 2.0, 'price' => 0.0]];
    }

    public function test_kit_option_sale_deducts_components_scaled(): void
    {
        ProductOptionService::deductOptionsForSale($this->kitSelected(), 4, 1, 1, 'pos', '#PDV00100', 100);

        // A: 3 per kit x 8 kits = 24; B: 1 per kit x 8 kits = 8.
        $this->assertSame(976.0, (float) $this->componentA->fresh()->quantity);
        $this->assertSame(492.0, (float) $this->componentB->fresh()->quantity);
        $this->assertSame(976.0, (float) \DB::table('warehouse_products')->where('product_id', $this->componentA->id)->value('quantity'));
        $this->assertSame(492.0, (float) \DB::table('warehouse_products')->where('product_id', $this->componentB->id)->value('quantity'));

        // The kit itself holds no stock and writes no ledger row.
        $this->assertSame(0.0, (float) $this->kit->fresh()->quantity);
        $this->assertSame(0, StockReport::where('product_id', $this->kit->id)->count());

        // Ledger rows carry the scaled component quantities (stock movement report).
        $rowA = StockReport::where('product_id', $this->componentA->id)->first();
        $this->assertNotNull($rowA);
        $this->assertSame(24, (int) $rowA->quantity);
        $this->assertSame('pos', $rowA->type);
        $this->assertStringContainsString('24', (string) $rowA->description);
    }

    public function test_kit_option_invoice_sale_writes_invoice_rows_without_warehouse_zero_rows(): void
    {
        ProductOptionService::deductOptionsForSale($this->kitSelected(), 4, 0, 1, 'invoice', 'INV-7', 7);

        $this->assertSame(976.0, (float) $this->componentA->fresh()->quantity);
        $rowA = StockReport::where('product_id', $this->componentA->id)->first();
        $this->assertNotNull($rowA);
        $this->assertSame('invoice', $rowA->type);
        $this->assertStringContainsString('quantity sold in invoice', (string) $rowA->description);
        // No junk warehouse-0 rows created by the invoice (warehouse-less) path.
        $this->assertSame(0, \DB::table('warehouse_products')->where('warehouse_id', 0)->count());
    }

    public function test_product_option_with_no_stock_toggle_deducts_nothing(): void
    {
        $displayOnly = $this->makeProduct('Display Only', 500, ['affect_stock' => false]);
        \DB::table('warehouse_products')->insert([
            'product_id' => $displayOnly->id, 'warehouse_id' => 1, 'quantity' => 500, 'created_by' => 1,
        ]);

        ProductOptionService::deductOptionsForSale(
            [['product_id' => $displayOnly->id, 'quantity' => 50.0, 'price' => 0.0]],
            4, 1, 1, 'pos', '#PDV00101', 101
        );

        $this->assertSame(500.0, (float) $displayOnly->fresh()->quantity);
        $this->assertSame(0, StockReport::where('product_id', $displayOnly->id)->count());
    }

    public function test_ingredient_option_deducts_scaled(): void
    {
        $ingredient = $this->makeProduct('Bogane', 3000, ['type' => 'ingredient', 'affect_stock' => true]);
        \DB::table('warehouse_products')->insert([
            'product_id' => $ingredient->id, 'warehouse_id' => 1, 'quantity' => 3000, 'created_by' => 1,
        ]);

        ProductOptionService::deductOptionsForSale(
            [['product_id' => $ingredient->id, 'quantity' => 40.0, 'price' => 0.0]],
            4, 1, 1, 'pos', '#PDV00102', 102
        );

        // 40 per latte x 4 lattes = 160.
        $this->assertSame(2840.0, (float) $ingredient->fresh()->quantity);
        $this->assertSame(160, (int) StockReport::where('product_id', $ingredient->id)->value('quantity'));
    }

    public function test_pos_void_restores_kit_components(): void
    {
        $parent = $this->makeProduct('Cafe Latte', 0, ['options_enabled' => true]);

        ProductOptionService::deductOptionsForSale($this->kitSelected(), 4, 1, 1, 'pos', '#PDV00103', 103);
        $this->assertSame(976.0, (float) $this->componentA->fresh()->quantity);

        $pos = new Pos();
        $pos->pos_id = 'PDV00103';
        $pos->warehouse_id = 1;
        $pos->created_by = 1;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $parent->id;
        $line->quantity = 4;
        $line->warehouse_id = 1;
        $line->selected_options_json = json_encode($this->kitSelected());
        $line->stock_deducted_at = now();
        $line->created_by = 1;
        $line->save();

        $this->assertTrue(PosLineStockRestoreService::restoreLine($pos, $line, 1, '#PDV00103'));
        $this->assertSame(1000.0, (float) $this->componentA->fresh()->quantity);
        $this->assertSame(500.0, (float) $this->componentB->fresh()->quantity);
        $this->assertSame(1000.0, (float) \DB::table('warehouse_products')->where('product_id', $this->componentA->id)->value('quantity'));
    }

    public function test_invoice_void_restore_returns_kit_components(): void
    {
        ProductOptionService::deductOptionsForSale($this->kitSelected(), 4, 1, 1, 'pos', '#PDV00104', 104);
        ProductOptionService::restoreOptionsForVoid($this->kitSelected(), 4, 1, 1, 'invoice_void', 'returned', 104);

        $this->assertSame(1000.0, (float) $this->componentA->fresh()->quantity);
        $this->assertSame(500.0, (float) $this->componentB->fresh()->quantity);
        $this->assertSame(0.0, (float) $this->kit->fresh()->quantity);
    }

    public function test_purchase_of_kit_option_stocks_components_not_the_kit(): void
    {
        ProductOptionService::addOptionsForPurchase($this->kitSelected(), 4, 1, 1, 'purchase', 'added', 105);

        $this->assertSame(1024.0, (float) $this->componentA->fresh()->quantity);
        $this->assertSame(508.0, (float) $this->componentB->fresh()->quantity);
        $this->assertSame(0.0, (float) $this->kit->fresh()->quantity);
    }
}
