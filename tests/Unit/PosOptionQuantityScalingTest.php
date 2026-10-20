<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Services\PosLineStockRestoreService;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An option's stored quantity is PER parent unit: selling 4 lattes whose
 * Capsula option is set up at 50 must move 200 capsules, not 50 — on the
 * sale, on the purchase receive, and mirrored on voids/restores.
 */
class PosOptionQuantityScalingTest extends TestCase
{
    private ProductService $option;

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

        $this->option = $this->makeProduct('Capsula de Cafe 12', 500);
        \DB::table('warehouse_products')->insert([
            'product_id' => $this->option->id, 'warehouse_id' => 1, 'quantity' => 500, 'created_by' => 1,
        ]);
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

    private function selected(): array
    {
        return [['product_id' => $this->option->id, 'quantity' => 50.0, 'price' => 0.0]];
    }

    public function test_sale_deduction_scales_with_line_quantity(): void
    {
        ProductOptionService::deductOptionsForSale($this->selected(), 4, 1, 1, 'pos', '#PDV00001', 1);

        $this->assertSame(300.0, (float) $this->option->fresh()->quantity);
        $this->assertSame(300.0, (float) \DB::table('warehouse_products')->where('product_id', $this->option->id)->value('quantity'));

        $row = StockReport::where('product_id', $this->option->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(200, (int) $row->quantity);
        $this->assertStringContainsString('200', (string) $row->description);
    }

    public function test_sale_deduction_single_unit_unchanged(): void
    {
        ProductOptionService::deductOptionsForSale($this->selected(), 1, 1, 1, 'pos', '#PDV00002', 1);

        $this->assertSame(450.0, (float) $this->option->fresh()->quantity);
        $this->assertSame(50, (int) StockReport::where('product_id', $this->option->id)->value('quantity'));
    }

    public function test_invoice_void_restore_scales_with_line_quantity(): void
    {
        ProductOptionService::restoreOptionsForVoid($this->selected(), 4, 1, 1, 'invoice_void', 'returned', 1);

        $this->assertSame(700.0, (float) $this->option->fresh()->quantity);
    }

    public function test_purchase_add_scales_with_line_quantity(): void
    {
        ProductOptionService::addOptionsForPurchase($this->selected(), 4, 1, 1, 'purchase', 'added', 1);

        $this->assertSame(700.0, (float) $this->option->fresh()->quantity);
    }

    public function test_pos_void_restore_scales_with_line_quantity(): void
    {
        $parent = $this->makeProduct('Cafe Latte', 0, ['options_enabled' => true]);

        $pos = new Pos();
        $pos->pos_id = 'PDV00009';
        $pos->warehouse_id = 1;
        $pos->created_by = 1;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $parent->id;
        $line->quantity = 4;
        $line->warehouse_id = 1;
        $line->selected_options_json = json_encode($this->selected());
        $line->stock_deducted_at = now();
        $line->created_by = 1;
        $line->save();

        $this->assertTrue(PosLineStockRestoreService::restoreLine($pos, $line, 1, '#PDV00009'));

        $this->assertSame(700.0, (float) $this->option->fresh()->quantity);
        $row = StockReport::where('product_id', $this->option->id)->where('type', 'pos')->first();
        $this->assertNotNull($row);
        $this->assertSame(-200, (int) $row->quantity);
    }
}
