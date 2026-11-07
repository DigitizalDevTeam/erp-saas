<?php

namespace Tests\Unit;

use App\Exceptions\CostCentreException;
use App\Services\AllocationEngine;
use Tests\TestCase;

class AllocationEngineTest extends TestCase
{
    protected AllocationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AllocationEngine();
    }

    private function lines(array $spec): array
    {
        $out = [];
        foreach ($spec as $i => [$centre, $value]) {
            $out[] = ['cost_centre_id' => $centre, 'percentage' => $value, 'fixed_amount' => null, 'sort_order' => $i];
        }

        return $out;
    }

    private function fixedLines(array $spec): array
    {
        $out = [];
        foreach ($spec as $i => [$centre, $value]) {
            $out[] = ['cost_centre_id' => $centre, 'percentage' => null, 'fixed_amount' => $value, 'sort_order' => $i];
        }

        return $out;
    }

    /** Plan §10 exit gate: MZN 60,000 at 40/35/25 = 24,000/21,000/15,000. */
    public function test_exit_gate_sixty_thousand_split(): void
    {
        $result = $this->engine->compute('60000.00', $this->lines([
            [1, '40.000000'], [2, '35.000000'], [3, '25.000000'],
        ]), 'percentage');

        $this->assertSame(['24000.00', '21000.00', '15000.00'], array_column($result['lines'], 'allocated_amount'));
        $this->assertSame('60000.00', $result['total_allocated']);
        $this->assertSame('0.00', $result['rounding_difference']);
    }

    public function test_ninety_nine_percent_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', $this->lines([[1, '50.000000'], [2, '49.000000']]), 'percentage');
    }

    public function test_one_hundred_and_one_percent_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', $this->lines([[1, '50.000000'], [2, '51.000000']]), 'percentage');
    }

    public function test_rounding_residual_is_deterministic_and_on_final_line(): void
    {
        $spec = $this->lines([[1, '33.333333'], [2, '33.333333'], [3, '33.333334']]);

        $first = $this->engine->compute('100.00', $spec, 'percentage');
        $second = $this->engine->compute('100.00', $spec, 'percentage');

        // 33.33 + 33.33 + 33.34: the 0.01 residual lands on the final line.
        $this->assertSame(['33.33', '33.33', '33.34'], array_column($first['lines'], 'allocated_amount'));
        $this->assertSame($first['lines'], $second['lines']);
        $this->assertSame('100.00', $first['total_allocated']);
        $this->assertSame('0.01', $first['lines'][2]['rounding_residual'] ?? null);
    }

    public function test_small_amount_rounding_residual(): void
    {
        $result = $this->engine->compute('10.00', $this->lines([
            [1, '33.333333'], [2, '33.333333'], [3, '33.333334'],
        ]), 'percentage');

        $this->assertSame(['3.33', '3.33', '3.34'], array_column($result['lines'], 'allocated_amount'));
        $this->assertSame('10.00', $result['total_allocated']);
    }

    public function test_fixed_amount_split_must_equal_source_amount(): void
    {
        $result = $this->engine->compute('60000.00', $this->fixedLines([
            [1, '24000.00'], [2, '21000.00'], [3, '15000.00'],
        ]), 'fixed_amount');

        $this->assertSame(['24000.00', '21000.00', '15000.00'], array_column($result['lines'], 'allocated_amount'));

        $this->expectException(CostCentreException::class);
        $this->engine->compute('60000.00', $this->fixedLines([
            [1, '24000.00'], [2, '20000.00'], [3, '15000.00'],
        ]), 'fixed_amount');
    }

    public function test_negative_split_is_rejected_unless_reversal(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', $this->lines([[1, '120.000000'], [2, '-20.000000']]), 'percentage');

        // A permitted reversal mirrors the original split (sums to -100%).
        $reversal = $this->engine->compute('1000.00', $this->lines([[1, '-60.000000'], [2, '-40.000000']]), 'percentage', true);
        $this->assertSame(['-600.00', '-400.00'], array_column($reversal['lines'], 'allocated_amount'));
    }

    public function test_duplicate_centre_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', $this->lines([[1, '50.000000'], [1, '50.000000']]), 'percentage');
    }

    public function test_mixed_percentage_and_fixed_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', [
            ['cost_centre_id' => 1, 'percentage' => '50.000000', 'fixed_amount' => '500.00', 'sort_order' => 0],
            ['cost_centre_id' => 2, 'percentage' => '50.000000', 'fixed_amount' => null, 'sort_order' => 1],
        ], 'percentage');
    }

    public function test_empty_split_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->engine->compute('1000.00', [], 'percentage');
    }

    public function test_single_centre_takes_the_whole_amount(): void
    {
        $result = $this->engine->compute('1234.56', $this->lines([[1, '100.000000']]), 'percentage');
        $this->assertSame(['1234.56'], array_column($result['lines'], 'allocated_amount'));
    }
}
