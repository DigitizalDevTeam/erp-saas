<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenRecipe;
use App\Models\ProductService;

/**
 * Recipe cost preview (plan §7, decision D9).
 *
 * Recipe cost = sum over lines of (ingredient quantity converted to
 * the ingredient's stock unit x ingredient purchase_price at
 * calculation time), grossed up by the cleaning / preparation loss %:
 * effective cost = base / (1 - loss%), because the lost share has to
 * be bought as well to reach the expected yield.
 *
 * Cost per portion = recipe cost / portions, where portions =
 * expected yield / standard portion (converted to the same unit).
 * Food cost % = cost per portion / selling price excluding VAT x 100
 * (selling price ex-VAT comes from ProductService::exclusiveUnitPrice()).
 *
 * Danilo's bands (decided 2026-11-06): below 25% excellent,
 * 25-28% excellent, 29-32% good, 33-36% good-, 37-41% bad,
 * over 41% very bad.
 */
class KitchenRecipeCostService
{
    public const BAND_EXCELLENT = 'excellent';
    public const BAND_GOOD = 'good';
    public const BAND_GOOD_MINUS = 'good-';
    public const BAND_BAD = 'bad';
    public const BAND_VERY_BAD = 'very_bad';

    public function __construct(
        protected KitchenUnitConversionService $conversions,
    ) {
    }

    public static function bandForFoodCostPercent(?float $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        if ($percent <= 28) {
            return self::BAND_EXCELLENT;
        }

        if ($percent <= 32) {
            return self::BAND_GOOD;
        }

        if ($percent <= 36) {
            return self::BAND_GOOD_MINUS;
        }

        if ($percent <= 41) {
            return self::BAND_BAD;
        }

        return self::BAND_VERY_BAD;
    }

    public static function bandLabel(?string $band): string
    {
        return match ($band) {
            self::BAND_EXCELLENT => __('Excellent'),
            self::BAND_GOOD => __('Good'),
            self::BAND_GOOD_MINUS => __('Good-'),
            self::BAND_BAD => __('Bad'),
            self::BAND_VERY_BAD => __('Very bad'),
            default => __('-'),
        };
    }

    /**
     * @return array{recipe_cost: float, portions: float|null, cost_per_portion: float|null, selling_price_ex_vat: float, food_cost_percent: float|null, band: string|null, lines: array<int, array{ingredient_id: int, quantity_stock_unit: float, unit_cost: float, line_cost: float}>}
     *
     * @throws KitchenConversionMissingException when a line cannot be converted.
     */
    public function calculate(KitchenRecipe $recipe): array
    {
        $creatorId = (int) $recipe->created_by;
        $base = 0.0;
        $lineCosts = [];

        foreach ($recipe->lines()->with('ingredientProduct')->get() as $line) {
            $ingredient = $line->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }

            $quantityInStockUnit = $this->conversions->convertToStockUnit(
                (float) $line->quantity,
                $line->unit_id ? (int) $line->unit_id : null,
                $ingredient,
                $creatorId
            );

            $unitCost = (float) ($ingredient->purchase_price ?? 0);
            $lineCost = round($quantityInStockUnit * $unitCost, 4);
            $base += $lineCost;

            $lineCosts[] = [
                'ingredient_id' => (int) $ingredient->id,
                'quantity_stock_unit' => $quantityInStockUnit,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ];
        }

        $lossPercent = (float) ($recipe->loss_percent ?? 0);
        $recipeCost = $lossPercent >= 100
            ? round($base, 2)
            : round($base / (1 - ($lossPercent / 100)), 2);

        $portions = $this->portions($recipe);
        $costPerPortion = $portions !== null && $portions > 0
            ? round($recipeCost / $portions, 2)
            : null;

        $dish = $recipe->product;
        $sellingPriceExVat = $dish instanceof ProductService
            ? (float) $dish->exclusiveUnitPrice((float) ($dish->sale_price ?? 0))
            : 0.0;

        $foodCostPercent = $costPerPortion !== null && $sellingPriceExVat > 0
            ? round(($costPerPortion / $sellingPriceExVat) * 100, 2)
            : null;

        return [
            'recipe_cost' => $recipeCost,
            'portions' => $portions,
            'cost_per_portion' => $costPerPortion,
            'selling_price_ex_vat' => $sellingPriceExVat,
            'food_cost_percent' => $foodCostPercent,
            'band' => self::bandForFoodCostPercent($foodCostPercent),
            'lines' => $lineCosts,
        ];
    }

    /** Portions per batch: expected yield / standard portion (same unit). */
    public function portions(KitchenRecipe $recipe): ?float
    {
        $yield = (float) ($recipe->expected_yield_quantity ?? 0);
        $portion = (float) ($recipe->standard_portion_quantity ?? 0);

        if ($yield <= 0 || $portion <= 0) {
            return null;
        }

        $yieldUnitId = (int) ($recipe->expected_yield_unit_id ?? 0);
        $portionUnitId = (int) ($recipe->standard_portion_unit_id ?? 0);

        if ($yieldUnitId > 0 && $portionUnitId > 0 && $yieldUnitId !== $portionUnitId) {
            $yield = $this->conversions->convert(
                $yield,
                $yieldUnitId,
                $portionUnitId,
                (int) $recipe->created_by
            );
        }

        return round($yield / $portion, 2);
    }
}
