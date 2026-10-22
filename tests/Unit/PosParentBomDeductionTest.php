<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductServiceController;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosParentBomDeductionTest extends TestCase
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
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('unit', 16)->nullable();
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

        $user = new User();
        $user->name = 'POS Tester';
        $user->email = 'pos-tester@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);
    }

    protected function tearDown(): void
    {
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
        $product->created_by = Auth::user()->creatorId();
        $product->save();

        return $product;
    }

    public function test_parent_recipe_ingredients_deducted_after_options(): void
    {
        $creatorId = Auth::user()->creatorId();
        $parent = $this->makeProduct('Coffee', 'product', 10, ['options_enabled' => true]);
        $powder = $this->makeProduct('Coffee Powder', 'ingredient', 100);

        ProductRecipe::create([
            'product_id' => $parent->id,
            'ingredient_product_id' => $powder->id,
            'quantity' => 2,
            'created_by' => $creatorId,
        ]);

        $controller = new PosController();
        $method = new \ReflectionMethod(PosController::class, 'deductParentBomAfterOptions');
        $method->setAccessible(true);
        // Sell 3 coffees: options were already deducted elsewhere; the 2 kg
        // of powder per coffee must still move.
        $method->invoke($controller, $parent, 3.0, 0, $creatorId, 'POS-1', 1);

        $this->assertEquals(94.0, (float) $powder->fresh()->quantity);
        // Parent itself holds no stock movement for a recipe dish.
        $this->assertEquals(10.0, (float) $parent->fresh()->quantity);
    }

    public function test_no_parent_deduction_without_recipes(): void
    {
        $creatorId = Auth::user()->creatorId();
        $parent = $this->makeProduct('Plain Shirt', 'product', 10, ['options_enabled' => true]);

        $controller = new PosController();
        $method = new \ReflectionMethod(PosController::class, 'deductParentBomAfterOptions');
        $method->setAccessible(true);
        $method->invoke($controller, $parent, 3.0, 0, $creatorId, 'POS-1', 1);

        $this->assertEquals(10.0, (float) $parent->fresh()->quantity);
    }

    public function test_recipe_quantity_edit_updates_existing_row(): void
    {
        $creatorId = Auth::user()->creatorId();
        $parent = $this->makeProduct('Coffee', 'product', 10);
        $powder = $this->makeProduct('Coffee Powder', 'ingredient', 100);

        ProductRecipe::create([
            'product_id' => $parent->id,
            'ingredient_product_id' => $powder->id,
            'quantity' => 1,
            'created_by' => $creatorId,
        ]);

        $request = new Request([
            'product_recipes' => [
                ['ingredient_product_id' => $powder->id, 'quantity' => '2.5', 'unit_id' => ''],
            ],
        ]);

        $method = new \ReflectionMethod(ProductServiceController::class, 'syncProductRecipes');
        $method->setAccessible(true);
        $method->invoke(new ProductServiceController(), $parent, $request);

        $this->assertSame(1, ProductRecipe::where('product_id', $parent->id)->count());
        $this->assertEquals(2.5, (float) ProductRecipe::where('product_id', $parent->id)->first()->quantity);
    }
}
