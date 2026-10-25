<?php

namespace Tests\Unit;

use App\Models\ProductService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-food default preparation time: the product stores its own
 * prep time in minutes (null = no own default, fall back to global),
 * and the migration adds the column without touching existing rows.
 */
class ProductPreparationTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_migration_adds_preparation_time_column(): void
    {
        $this->assertFalse(Schema::hasColumn('product_services', 'preparation_time'));

        $migration = require base_path('database/migrations/2026_10_25_230000_add_preparation_time_to_product_services.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('product_services', 'preparation_time'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('product_services', 'preparation_time'));
    }

    public function test_product_stores_preparation_time_as_integer(): void
    {
        Schema::table('product_services', function ($table) {
            $table->unsignedSmallInteger('preparation_time')->nullable();
        });

        $product = ProductService::create([
            'name' => 'Grilled Fish',
            'type' => 'product',
            'created_by' => 1,
            'preparation_time' => 25,
        ]);

        $this->assertSame(25, $product->fresh()->preparation_time);

        $plain = ProductService::create([
            'name' => 'Salad',
            'type' => 'product',
            'created_by' => 1,
        ]);
        $this->assertNull($plain->fresh()->preparation_time);
    }
}
