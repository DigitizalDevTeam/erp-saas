<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenUnitConversion;
use App\Models\ProductService;

/**
 * Factor-based unit conversion for the Kitchen Production module.
 *
 * A conversion row says: quantity in from_unit * factor = quantity in
 * to_unit (e.g. 1 bag of 25 kg -> kg has factor 25; 1 kg -> g has
 * factor 1000). Conversions are company-scoped; a row may be limited
 * to one product (product_id) or apply company-wide (product_id null).
 *
 * Conversion may be direct, reversed (factor is inverted) or chained
 * through intermediate units (bag -> kg -> g). If no path exists the
 * service throws KitchenConversionMissingException — callers must
 * block approval, never guess a factor.
 */
class KitchenUnitConversionService
{
    /**
     * Convert a quantity from one unit to another.
     *
     * @throws KitchenConversionMissingException when no conversion path exists.
     */
    public function convert(float $quantity, int $fromUnitId, int $toUnitId, int $creatorId, ?int $productId = null): float
    {
        if ($fromUnitId === $toUnitId) {
            return round($quantity, 4);
        }

        $factor = $this->findFactor($fromUnitId, $toUnitId, $creatorId, $productId);

        if ($factor === null) {
            throw new KitchenConversionMissingException(
                __('No unit conversion is defined from unit :from to unit :to. Define the conversion before approving this recipe.', [
                    'from' => $fromUnitId,
                    'to' => $toUnitId,
                ])
            );
        }

        return round($quantity * $factor, 4);
    }

    public function hasConversion(int $fromUnitId, int $toUnitId, int $creatorId, ?int $productId = null): bool
    {
        if ($fromUnitId === $toUnitId) {
            return true;
        }

        return $this->findFactor($fromUnitId, $toUnitId, $creatorId, $productId) !== null;
    }

    /**
     * Convert a recipe-line quantity to the ingredient's stock unit
     * (the ingredient product's own unit_id). Returns the quantity
     * unchanged when the line is already in the stock unit.
     *
     * @throws KitchenConversionMissingException when no conversion path exists.
     */
    public function convertToStockUnit(float $quantity, ?int $lineUnitId, ProductService $ingredient, int $creatorId): float
    {
        $stockUnitId = (int) ($ingredient->unit_id ?? 0);

        if ($lineUnitId === null || $lineUnitId <= 0 || $stockUnitId <= 0) {
            return round($quantity, 4);
        }

        return $this->convert($quantity, $lineUnitId, $stockUnitId, $creatorId, (int) $ingredient->id);
    }

    /**
     * Shortest path over the conversion graph. Each stored row is an
     * edge from_unit -> to_unit with its factor; the reverse edge
     * exists with factor 1/factor. Product-specific rows win over
     * company-wide rows for the same pair.
     *
     * Cost is lexicographic (generic edges, steps): a path using
     * fewer company-wide edges wins, then the shorter path. A cycle
     * always adds steps (and never removes a generic edge), so it can
     * never improve a cost — the search always terminates.
     */
    protected function findFactor(int $fromUnitId, int $toUnitId, int $creatorId, ?int $productId = null): ?float
    {
        $rows = KitchenUnitConversion::where('created_by', $creatorId)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        // Product-specific rows first so they win over company-wide rows.
        $edges = [];
        foreach ($rows as $row) {
            $factor = (float) $row->factor;
            if ($factor <= 0) {
                continue;
            }
            $specific = $row->product_id !== null && $productId !== null && (int) $row->product_id === $productId;
            $edges[] = [
                'from' => (int) $row->from_unit_id,
                'to' => (int) $row->to_unit_id,
                'factor' => $factor,
                'specific' => $specific,
            ];
            $edges[] = [
                'from' => (int) $row->to_unit_id,
                'to' => (int) $row->from_unit_id,
                'factor' => 1 / $factor,
                'specific' => $specific,
            ];
        }

        // Best known cost per unit: ['generic' => int, 'steps' => int, 'factor' => float].
        $best = [$fromUnitId => ['generic' => 0, 'steps' => 0, 'factor' => 1.0]];
        $queue = [$fromUnitId];

        while ($queue !== []) {
            // Always expand the cheapest known unit first.
            usort($queue, function ($a, $b) use ($best) {
                return [$best[$a]['generic'], $best[$a]['steps']] <=> [$best[$b]['generic'], $best[$b]['steps']];
            });
            $current = array_shift($queue);
            $currentBest = $best[$current];

            foreach ($edges as $edge) {
                if ($edge['from'] !== $current) {
                    continue;
                }
                $candidate = [
                    'generic' => $currentBest['generic'] + ($edge['specific'] ? 0 : 1),
                    'steps' => $currentBest['steps'] + 1,
                    'factor' => $currentBest['factor'] * $edge['factor'],
                ];
                $target = $edge['to'];
                if (! isset($best[$target])
                    || [$candidate['generic'], $candidate['steps']] < [$best[$target]['generic'], $best[$target]['steps']]) {
                    $best[$target] = $candidate;
                    $queue[] = $target;
                }
            }
        }

        return isset($best[$toUnitId]) ? (float) $best[$toUnitId]['factor'] : null;
    }
}
