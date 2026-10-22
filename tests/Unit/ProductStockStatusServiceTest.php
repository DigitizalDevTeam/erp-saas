<?php

namespace Tests\Unit;

use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PosWarehouseAvailabilityService;
use App\Services\ProductStockStatusService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Products list "Stock Status" popup chart: the product, its
 * assigned options and its recipe ingredients, per warehouse — plus
 * the "can sell" row, which must match the POS availability check.
 */
class ProductStockStatusServiceTest extends TestCase
{
    private int $creatorId = 1;
    private warehouse $bar;
    private warehouse $bebidas;
    private ProductService $sumo;

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
        $user->name = 'Company';
        $user->email = 'company@example.com';
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
            $table->integer('low_stock_threshold')->nullable();
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
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_option_items', function ($table) {
            $table->increments('id');
            $table->integer('parent_product_id');
            $table->integer('option_product_id');
            $table->string('option_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
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

        $this->bar = $this->makeWarehouse('Armazém | BAR');
        $this->bebidas = $this->makeWarehouse('BEBIDAS');

        $this->sumo = $this->makeProduct('Sumo Natural', '0000512', 'product', 10);
        $laranjas = $this->makeProduct('Laranjas', '0000434', 'product', 5);
        $acucar = $this->makeProduct('Açúcar', '0000721', 'ingredient', null);
        $hortela = $this->makeProduct('Hortelã', '0000733', 'ingredient', 2);

        $this->stock($this->sumo->id, $this->bar->id, 25);
        $this->stock($this->sumo->id, $this->bebidas->id, 8);
        $this->stock($laranjas->id, $this->bar->id, 100);
        $this->stock($acucar->id, $this->bar->id, 60);
        $this->stock($acucar->id, $this->bebidas->id, 3);
        $this->stock($hortela->id, $this->bebidas->id, 7);

        $option = new ProductOptionItem();
        $option->parent_product_id = $this->sumo->id;
        $option->option_product_id = $laranjas->id;
        $option->quantity = 1;
        $option->created_by = $this->creatorId;
        $option->save();

        $this->recipe($this->sumo->id, $acucar->id, 0.5);
        $this->recipe($this->sumo->id, $hortela->id, 1);
    }

    private function makeWarehouse(string $name): warehouse
    {
        $w = new warehouse();
        $w->name = $name;
        $w->created_by = $this->creatorId;
        $w->save();

        return $w;
    }

    private function makeProduct(string $name, string $sku, string $type, ?int $lowThreshold): ProductService
    {
        $p = new ProductService();
        $p->name = $name;
        $p->sku = $sku;
        $p->type = $type;
        $p->affect_stock = true;
        $p->low_stock_threshold = $lowThreshold;
        $p->created_by = $this->creatorId;
        $p->save();

        return $p;
    }

    private function stock(int $productId, int $warehouseId, float $qty): void
    {
        $row = new WarehouseProduct();
        $row->product_id = $productId;
        $row->warehouse_id = $warehouseId;
        $row->quantity = $qty;
        $row->created_by = $this->creatorId;
        $row->save();
    }

    private function recipe(int $productId, int $ingredientId, float $qty): void
    {
        $r = new ProductRecipe();
        $r->product_id = $productId;
        $r->ingredient_product_id = $ingredientId;
        $r->quantity = $qty;
        $r->created_by = $this->creatorId;
        $r->save();
    }

    private function chart(): array
    {
        return (new ProductStockStatusService(new PosWarehouseAvailabilityService()))
            ->chartFor($this->sumo, $this->creatorId);
    }

    public function test_chart_lists_product_options_and_ingredients_per_warehouse(): void
    {
        $chart = $this->chart();

        $this->assertSame(['Armazém | BAR', 'BEBIDAS'], array_column($chart['warehouses'], 'name'));

        $product = $chart['product_stock'];
        $this->assertSame(25.0, $product['stock'][$this->bar->id]);
        $this->assertSame(8.0, $product['stock'][$this->bebidas->id]);
        $this->assertSame(33.0, $product['total']);
        $this->assertNull($product['per_sale']);
        $this->assertSame(10, $product['low_stock_threshold']);

        $this->assertCount(1, $chart['options']);
        $option = $chart['options'][0];
        $this->assertSame('Laranjas', $option['name']);
        $this->assertSame(1.0, $option['per_sale']);
        $this->assertSame(100.0, $option['stock'][$this->bar->id]);
        $this->assertSame(0.0, $option['stock'][$this->bebidas->id]);
        $this->assertSame(5, $option['low_stock_threshold']);

        $this->assertCount(2, $chart['ingredients']);
        $byName = collect($chart['ingredients'])->keyBy('name');
        $this->assertSame(0.5, $byName['Açúcar']['per_sale']);
        $this->assertSame(63.0, $byName['Açúcar']['total']);
        $this->assertSame(0.0, $byName['Hortelã']['stock'][$this->bar->id]);
        $this->assertSame(7.0, $byName['Hortelã']['total']);
    }

    public function test_can_sell_matches_pos_availability(): void
    {
        $chart = $this->chart();
        $sellable = $chart['product_stock']['sellable'];

        // BAR: Hortelã is 0 -> nothing sellable even with 100 Laranjas.
        $this->assertSame(0, $sellable[$this->bar->id]);
        // BEBIDAS: min(own 8, Açúcar floor(3 / 0.5) = 6, Hortelã 7) = 6.
        $this->assertSame(6, $sellable[$this->bebidas->id]);
    }

    public function test_option_sellable_includes_its_own_recipe(): void
    {
        // An option is a product too: Café Expresso is an option of
        // Sumo Natural (per sale 2) and itself needs Café em Grão.
        $expresso = $this->makeProduct('Café Expresso', '0000455', 'product', null);
        $grao = $this->makeProduct('Café em Grão', '0000456', 'ingredient', null);
        $this->stock($expresso->id, $this->bar->id, 10);
        $this->stock($expresso->id, $this->bebidas->id, 4);
        $this->stock($grao->id, $this->bar->id, 3);
        $this->recipe($expresso->id, $grao->id, 1);

        $option = new ProductOptionItem();
        $option->parent_product_id = $this->sumo->id;
        $option->option_product_id = $expresso->id;
        $option->quantity = 2;
        $option->created_by = $this->creatorId;
        $option->save();

        $chart = $this->chart();
        $row = collect($chart['options'])->firstWhere('name', 'Café Expresso');

        $this->assertNotNull($row);
        $this->assertSame(2.0, $row['per_sale']);
        // Own stock alone would allow 10 / 4 — its recipe caps it:
        // BAR min(10, 3) = 3 (badge: Can sell x1), BEBIDAS min(4, 0) = 0.
        $this->assertSame(3, $row['sellable'][$this->bar->id]);
        $this->assertSame(0, $row['sellable'][$this->bebidas->id]);
    }

    public function test_no_affect_stock_items_are_flagged_for_dash_display(): void
    {
        // A kit (never affects stock itself) with a no-stock option: the
        // popup shows "—" + a "Does not affect stock" tag for both rows,
        // driven by this flag.
        $kit = $this->makeProduct('Menu Executivo', '0000800', 'kit', null);
        $kit->affect_stock = false;
        $kit->save();

        $napkin = $this->makeProduct('Guardanapo', '0000810', 'product', null);
        $napkin->affect_stock = false;
        $napkin->save();
        $this->stock($napkin->id, $this->bar->id, 500);

        $option = new ProductOptionItem();
        $option->parent_product_id = $kit->id;
        $option->option_product_id = $napkin->id;
        $option->quantity = 1;
        $option->created_by = $this->creatorId;
        $option->save();

        $chart = (new ProductStockStatusService(new PosWarehouseAvailabilityService()))
            ->chartFor($kit, $this->creatorId);

        $this->assertFalse($chart['product_stock']['affect_stock']);
        $this->assertCount(1, $chart['options']);
        $this->assertFalse($chart['options'][0]['affect_stock']);
        // Stock numbers are still in the payload; the popup hides them.
        $this->assertSame(500.0, $chart['options'][0]['stock'][$this->bar->id]);
    }

    public function test_service_without_stock_or_recipe_is_unlimited(): void
    {
        $service = $this->makeProduct('Corte de Cabelo', '0000900', 'service', null);
        $service->affect_stock = false;
        $service->save();

        $chart = (new ProductStockStatusService(new PosWarehouseAvailabilityService()))
            ->chartFor($service, $this->creatorId);

        $this->assertSame($chart['unlimited_value'], $chart['product_stock']['sellable'][$this->bar->id]);
        $this->assertSame([], $chart['options']);
        $this->assertSame([], $chart['ingredients']);
    }
}
