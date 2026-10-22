<?php

namespace App\Support;

use App\Models\ProductService;

/**
 * Measurement units for per-sale quantities on product option rows and
 * recipe rows (e.g. Leite x 50 ML, Café Grão x 10 G). One shared list so
 * the Product Options and Recipe Ingredients sections always show the
 * same units. Stored as the code (e.g. 'ML') on the row.
 */
class MeasurementUnits
{
    /** @return array<string, string> code => dropdown label */
    public static function all(): array
    {
        return [
            'UNIT' => 'UNIT — Unit',
            'PCS' => 'PCS — Pieces',
            'DOZEN' => 'DOZEN — Dozen',
            'PACK' => 'PACK — Pack',
            'ML' => 'ML — Milliliter',
            'CL' => 'CL — Centiliter',
            'L' => 'L — Liter',
            'MG' => 'MG — Milligram',
            'G' => 'G — Gram',
            'KG' => 'KG — Kilogram',
            'OZ' => 'OZ — Ounce',
            'LB' => 'LB — Pound',
            'TSP' => 'TSP — Teaspoon',
            'TBSP' => 'TBSP — Tablespoon',
            'MM' => 'MM — Millimeter',
            'CM' => 'CM — Centimeter',
            'M' => 'M — Meter',
            'SQM' => 'SQM — Square meter',
            'CBM' => 'CBM — Cubic meter',
        ];
    }

    /** @return string[] */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Canonical code for raw form input, or null when empty/unknown.
     */
    public static function normalize(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $code = strtoupper(trim((string) $raw));

        return $code !== '' && isset(self::all()[$code]) ? $code : null;
    }

    public static function label(?string $code): string
    {
        $code = self::normalize($code);

        return $code !== null ? self::all()[$code] : '';
    }

    /**
     * Best default unit for a linked product: its own unit master name
     * when that is a measurement code (e.g. a unit named "KG"), else a
     * mapping from how its stock is counted (piece/kg/cbm/sqm).
     */
    public static function suggestForProduct(?ProductService $product): ?string
    {
        if (! $product) {
            return null;
        }

        $unitName = null;
        if ($product->relationLoaded('unit')) {
            $unitName = $product->unit->name ?? null;
        } elseif (! empty($product->unit_id)) {
            $unitName = $product->unit()->value('name');
        }
        $fromName = self::normalize($unitName);
        if ($fromName !== null) {
            return $fromName;
        }
        // Common base-unit spellings that are not codes themselves.
        $aliases = ['UN' => 'UNIT', 'UNI' => 'UNIT', 'PC' => 'PCS', 'LT' => 'L', 'LTR' => 'L', 'GR' => 'G'];
        $upper = strtoupper(trim((string) ($unitName ?? '')));
        if (isset($aliases[$upper])) {
            return $aliases[$upper];
        }

        // Product form "How stock is counted" (unit_type):
        // kg / unit (piece) / cbm / sm (square meter) / volume.
        return match (strtolower((string) ($product->unit_type ?? ''))) {
            'kg' => 'KG',
            'cbm' => 'CBM',
            'sm' => 'SQM',
            'unit' => 'UNIT',
            'volume' => 'KG',
            default => null,
        };
    }
}
