<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Models\User;
use App\Services\ProductOptionService;
use App\Support\MeasurementUnits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Measurement units on product option rows and recipe rows: the shared
 * list, normalization, per-product suggestion, and both save paths
 * (ProductOptionService::syncProductOptions and the controller's
 * syncProductRecipes).
 */
class MeasurementUnitsTest extends TestCase
{
    private int $creatorId = 77;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->boolean('options_enabled')->default(false);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_type')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_option_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('parent_product_id');
            $table->unsignedBigInteger('option_product_id');
            $table->string('option_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 16)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_product_id');
            $table->decimal('quantity', 16, 4)->default(1);
            $table->string('unit', 16)->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->id = $this->creatorId;
        $user->name = 'Tester';
        $user->created_by = $this->creatorId;
        Auth::login($user);
    }

    private function makeProduct(string $name, string $type, array $extra = []): ProductService
    {
        $p = new ProductService();
        $p->name = $name;
        $p->type = $type;
        $p->created_by = $this->creatorId;
        foreach ($extra as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    public function test_list_contains_the_required_units(): void
    {
        foreach (['UNIT', 'ML', 'L', 'G', 'KG', 'MG', 'LB', 'OZ', 'PCS', 'DOZEN'] as $code) {
            $this->assertArrayHasKey($code, MeasurementUnits::all(), "missing unit {$code}");
        }
    }

    public function test_normalize(): void
    {
        $this->assertSame('ML', MeasurementUnits::normalize('ml'));
        $this->assertSame('KG', MeasurementUnits::normalize(' KG '));
        $this->assertNull(MeasurementUnits::normalize('bogus'));
        $this->assertNull(MeasurementUnits::normalize(''));
        $this->assertNull(MeasurementUnits::normalize(null));
    }

    public function test_suggest_for_product(): void
    {
        $kgUnit = new ProductServiceUnit();
        $kgUnit->name = 'KG';
        $byName = $this->makeProduct('By name', 'product');
        $byName->setRelation('unit', $kgUnit);
        $this->assertSame('KG', MeasurementUnits::suggestForProduct($byName));

        $unUnit = new ProductServiceUnit();
        $unUnit->name = 'UN';
        $byAlias = $this->makeProduct('By alias', 'product');
        $byAlias->setRelation('unit', $unUnit);
        $this->assertSame('UNIT', MeasurementUnits::suggestForProduct($byAlias));

        $byType = $this->makeProduct('By type', 'product', ['unit_type' => 'sm']);
        $byType->setRelation('unit', null);
        $this->assertSame('SQM', MeasurementUnits::suggestForProduct($byType));

        $none = $this->makeProduct('None', 'product');
        $none->setRelation('unit', null);
        $this->assertNull(MeasurementUnits::suggestForProduct($none));
    }

    public function test_option_sync_persists_unit(): void
    {
        $parent = $this->makeProduct('Café Latte', 'product');
        $leite = $this->makeProduct('Leite', 'product');

        $request = Request::create('/', 'POST', [
            'options_enabled' => 1,
            'product_options' => [
                ['option_product_id' => $leite->id, 'option_group' => '', 'is_required' => '0', 'quantity' => 50, 'unit' => 'ml'],
            ],
        ]);
        ProductOptionService::syncProductOptions($parent, $request);

        $item = ProductOptionItem::first();
        $this->assertNotNull($item);
        $this->assertSame('ML', $item->unit);
        $this->assertSame(50.0, (float) $item->quantity);

        // Invalid unit is stored as null, never as free text.
        $request2 = Request::create('/', 'POST', [
            'options_enabled' => 1,
            'product_options' => [
                ['option_product_id' => $leite->id, 'option_group' => '', 'is_required' => '0', 'quantity' => 50, 'unit' => 'splash'],
            ],
        ]);
        ProductOptionService::syncProductOptions($parent, $request2);
        $this->assertNull(ProductOptionItem::first()->unit);
    }

    public function test_recipe_sync_persists_unit_on_create_and_update(): void
    {
        $parent = $this->makeProduct('Sumo Natural', 'product');
        $acucar = $this->makeProduct('Açúcar', 'ingredient');

        $sync = new \ReflectionMethod(ProductServiceController::class, 'syncProductRecipes');
        $sync->setAccessible(true);
        $controller = new ProductServiceController();

        $create = Request::create('/', 'POST', [
            'product_recipes' => [
                ['ingredient_product_id' => $acucar->id, 'quantity' => 0.5, 'unit' => 'kg'],
            ],
        ]);
        $sync->invoke($controller, $parent, $create);

        $row = ProductRecipe::first();
        $this->assertNotNull($row);
        $this->assertSame('KG', $row->unit);

        $update = Request::create('/', 'POST', [
            'product_recipes' => [
                ['ingredient_product_id' => $acucar->id, 'quantity' => 20, 'unit' => 'G'],
            ],
        ]);
        $sync->invoke($controller, $parent, $update);

        $row->refresh();
        $this->assertSame('G', $row->unit);
        $this->assertSame(20.0, (float) $row->quantity);
        $this->assertSame(1, ProductRecipe::count());
    }
}
