<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceCategoryController;
use App\Models\ProductServiceCategory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Show on POS" per-category toggle: hidden categories must disappear
 * from both POS category feeds (restaurant JSON tree + retail HTML
 * buttons) while staying in the data; NULL (pre-toggle rows) counts
 * as shown.
 */
class CategoryShowOnPosTest extends TestCase
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
        Schema::create('product_service_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('print_zone')->nullable();
            $table->string('color')->nullable();
            $table->unsignedBigInteger('chart_account_id')->nullable();
            $table->boolean('show_on_pos')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type')->nullable();
            $table->boolean('show_on_pos')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $user = new User();
        $user->id = $this->creatorId;
        $user->name = 'Tester';
        $user->created_by = $this->creatorId;
        Auth::login($user);
    }

    private function makeCategory(string $name, $type, ?bool $showOnPos, ?int $parentId = null): ProductServiceCategory
    {
        $c = new ProductServiceCategory();
        $c->name = $name;
        $c->type = $type;
        $c->parent_id = $parentId;
        $c->show_on_pos = $showOnPos;
        $c->created_by = $this->creatorId;
        $c->save();

        return $c;
    }

    public function test_json_feed_hides_categories_toggled_off(): void
    {
        $comidas = $this->makeCategory('Comidas', 'product & service', true);
        $this->makeCategory('Congelados', 'product & service', false);
        $this->makeCategory('Águas', 'product & service', null); // legacy row: shown
        $this->makeCategory('Enlatados', 'product & service', true, $comidas->id);
        $this->makeCategory('EXTRAS', 'product & service', false, $comidas->id);

        $response = (new ProductServiceCategoryController())->getProductCategoriesJson();
        $data = json_decode($response->getContent(), true);

        $names = array_column($data['categories'], 'name');
        $this->assertContains('Comidas', $names);
        $this->assertContains('Águas', $names);
        $this->assertContains('Enlatados', $names);
        $this->assertNotContains('Congelados', $names);
        $this->assertNotContains('EXTRAS', $names);

        $childNames = array_column($data['children_by_parent'][$comidas->id] ?? [], 'name');
        $this->assertSame(['Enlatados'], $childNames);
    }

    public function test_html_feed_hides_categories_toggled_off(): void
    {
        $this->makeCategory('Retail Visible', 0, true);
        $this->makeCategory('Retail Hidden', 0, false);
        $this->makeCategory('Retail Legacy', 0, null);

        $response = (new ProductServiceCategoryController())->getProductCategories();
        $html = $response->getContent();

        $this->assertStringContainsString('Retail Visible', $html);
        $this->assertStringContainsString('Retail Legacy', $html);
        $this->assertStringNotContainsString('Retail Hidden', $html);
    }

    public function test_forms_and_save_paths_carry_the_toggle(): void
    {
        foreach (['create', 'edit'] as $view) {
            $blade = file_get_contents(resource_path("views/productServiceCategory/{$view}.blade.php"));
            $this->assertStringContainsString('name="show_on_pos"', $blade, $view);
            $this->assertStringContainsString("__('Show on POS')", $blade, $view);
        }
        $create = file_get_contents(resource_path('views/productServiceCategory/create.blade.php'));
        $this->assertStringContainsString('value="1" checked', $create, 'create defaults to ON');

        $controller = file_get_contents(app_path('Http/Controllers/ProductServiceCategoryController.php'));
        $this->assertSame(2, substr_count($controller, "show_on_pos = \$request->boolean('show_on_pos')"), 'store + update persist the toggle');
    }
}
