<?php

namespace Tests\Unit;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenUnitConversion;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenUnitConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('kitchen_unit_conversions', function ($table) {
            $table->increments('id');
            $table->integer('from_unit_id');
            $table->integer('to_unit_id');
            $table->decimal('factor', 18, 6);
            $table->integer('product_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('kitchen_unit_conversions');

        parent::tearDown();
    }

    private function service(): KitchenUnitConversionService
    {
        return new KitchenUnitConversionService();
    }

    private function addConversion(int $from, int $to, float $factor, int $creatorId = 1, ?int $productId = null): void
    {
        KitchenUnitConversion::create([
            'from_unit_id' => $from,
            'to_unit_id' => $to,
            'factor' => $factor,
            'product_id' => $productId,
            'created_by' => $creatorId,
        ]);
    }

    public function test_direct_conversion_kg_to_grams(): void
    {
        // Unit 1 = kg, unit 2 = g.
        $this->addConversion(1, 2, 1000);

        $this->assertSame(150.0, $this->service()->convert(0.15, 1, 2, 1));
        $this->assertSame(25000.0, $this->service()->convert(25, 1, 2, 1));
    }

    public function test_reverse_conversion_grams_to_kg(): void
    {
        $this->addConversion(1, 2, 1000);

        $this->assertSame(0.15, $this->service()->convert(150, 2, 1, 1));
    }

    public function test_chained_conversion_bag_to_grams(): void
    {
        // Unit 3 = 25 kg bag, unit 1 = kg, unit 2 = g.
        $this->addConversion(3, 1, 25);
        $this->addConversion(1, 2, 1000);

        $this->assertSame(25000.0, $this->service()->convert(1, 3, 2, 1));
        $this->assertSame(50000.0, $this->service()->convert(2, 3, 2, 1));
    }

    public function test_litres_to_millilitres(): void
    {
        // Unit 4 = L, unit 5 = ml.
        $this->addConversion(4, 5, 1000);

        $this->assertSame(800.0, $this->service()->convert(0.8, 4, 5, 1));
    }

    public function test_same_unit_returns_quantity_unchanged(): void
    {
        $this->assertSame(42.5, $this->service()->convert(42.5, 1, 1, 1));
    }

    public function test_missing_conversion_throws_instead_of_guessing(): void
    {
        $this->addConversion(1, 2, 1000);

        $this->expectException(KitchenConversionMissingException::class);
        $this->service()->convert(1, 1, 4, 1);
    }

    public function test_conversions_are_scoped_per_company(): void
    {
        $this->addConversion(1, 2, 1000, 1);

        $this->expectException(KitchenConversionMissingException::class);
        $this->service()->convert(1, 1, 2, 2);
    }

    public function test_product_specific_conversion_wins_over_company_wide(): void
    {
        // Company-wide: 1 unit-3 = 20 kg. Product 7 is bought in 25 kg bags.
        $this->addConversion(3, 1, 20, 1, null);
        $this->addConversion(3, 1, 25, 1, 7);

        $this->assertSame(25.0, $this->service()->convert(1, 3, 1, 1, 7));
        $this->assertSame(20.0, $this->service()->convert(1, 3, 1, 1, 8));
    }

    public function test_cyclic_conversions_terminate(): void
    {
        // Explicit two-way rows plus a chained target: the reverse edges
        // create cycles, and the search must still finish.
        $this->addConversion(1, 2, 1000);
        $this->addConversion(2, 1, 0.001);
        $this->addConversion(3, 1, 25);

        $this->assertSame(25000.0, $this->service()->convert(1, 3, 2, 1));
        $this->assertSame(0.04, $this->service()->convert(1, 1, 3, 1));
    }
}
