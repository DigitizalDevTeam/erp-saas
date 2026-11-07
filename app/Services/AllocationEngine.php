<?php

namespace App\Services;

use App\Exceptions\CostCentreException;

/**
 * Allocation engine (Cost Centre Phase 1, plan §5).
 *
 * Pure decimal arithmetic only — bcmath throughout, never float rounding.
 * Splits an amount by percentage or fixed amount, rounds each line to
 * minor units (2 decimals) and puts the residual on the deterministic
 * FINAL eligible line (highest sort_order, tie-break lowest
 * cost_centre_id), so two runs of the same split produce the same
 * amounts. This class does no database work except nothing at all: it
 * validates structure and arithmetic only. Centre status, company,
 * budget policy and period checks live in CostCentreAllocationService,
 * which calls this engine.
 */
class AllocationEngine
{
    public const VERSION = '1.0';

    /** Percentage points: splits must sum to 100 within this tolerance. */
    public const SUM_TOLERANCE = '0.000001';

    /** Internal precision for raw (unrounded) amounts. */
    public const SCALE = 10;

    /**
     * Compute a split. Returns the computed lines plus totals.
     *
     * @param  string  $amount  Source line amount, decimal string, 2dp.
     * @param  array  $lines  Each: ['cost_centre_id' => int,
     *                         'percentage' => string|null,
     *                         'fixed_amount' => string|null,
     *                         'sort_order' => int]
     * @param  string  $basis  'percentage' | 'fixed_amount'
     * @param  bool  $isReversal  If true, negative splits are permitted.
     *
     * @throws CostCentreException on any validation failure.
     */
    public function compute(string $amount, array $lines, string $basis, bool $isReversal = false): array
    {
        $amount = $this->normaliseAmount($amount);
        $lines = $this->normaliseLines($lines, $basis, $isReversal);

        if ($basis === 'percentage') {
            $this->validatePercentageSum($lines, $isReversal);
            $computed = $this->splitByPercentage($amount, $lines);
        } elseif ($basis === 'fixed_amount') {
            $this->validateFixedSum($amount, $lines, $isReversal);
            $computed = $this->splitByFixed($amount, $lines);
        } else {
            throw new CostCentreException(__('Invalid allocation basis.'));
        }

        $total = '0.00';
        foreach ($computed as $row) {
            $total = bcadd($total, $row['allocated_amount'], 2);
        }

        return [
            'lines' => $computed,
            'total_allocated' => $total,
            'source_amount' => $amount,
            'rounding_difference' => bcsub($amount, $total, 2),
            'basis' => $basis,
        ];
    }

    /**
     * Validate only (no amounts needed beyond the split rules): returns
     * the computed split — the validation endpoint uses this directly.
     */
    public function validate(string $amount, array $lines, string $basis, bool $isReversal = false): array
    {
        return $this->compute($amount, $lines, $basis, $isReversal);
    }

    /** Normalise + structurally validate the input rows. */
    protected function normaliseLines(array $lines, string $basis, bool $isReversal): array
    {
        if ($lines === []) {
            throw new CostCentreException(__('At least one cost centre split line is required.'));
        }

        $seen = [];
        $clean = [];
        foreach ($lines as $index => $line) {
            $centreId = (int) ($line['cost_centre_id'] ?? 0);
            if ($centreId <= 0) {
                throw new CostCentreException(__('Each split line must select a cost centre.'));
            }
            if (isset($seen[$centreId])) {
                throw new CostCentreException(__('A cost centre may only appear once in a split.'));
            }
            $seen[$centreId] = true;

            $percentage = $this->nullOrDecimal($line['percentage'] ?? null, 6);
            $fixed = $this->nullOrDecimal($line['fixed_amount'] ?? null, 2);

            if ($basis === 'percentage') {
                if ($percentage === null) {
                    throw new CostCentreException(__('Each split line needs a percentage.'));
                }
                if ($fixed !== null) {
                    throw new CostCentreException(__('A percentage split cannot also carry a fixed amount.'));
                }
                if (! $isReversal && bccomp($percentage, '0', 6) <= 0) {
                    throw new CostCentreException(__('Split percentages must be greater than zero. Negative splits are only allowed on a permitted reversal.'));
                }
                if ($isReversal && bccomp($percentage, '0', 6) === 0) {
                    throw new CostCentreException(__('Split percentages must not be zero.'));
                }
            } else {
                if ($fixed === null) {
                    throw new CostCentreException(__('Each split line needs a fixed amount.'));
                }
                if ($percentage !== null) {
                    throw new CostCentreException(__('A fixed-amount split cannot also carry a percentage.'));
                }
                if (! $isReversal && bccomp($fixed, '0', 2) <= 0) {
                    throw new CostCentreException(__('Split amounts must be greater than zero. Negative splits are only allowed on a permitted reversal.'));
                }
                if ($isReversal && bccomp($fixed, '0', 2) === 0) {
                    throw new CostCentreException(__('Split amounts must not be zero.'));
                }
            }

            $clean[] = [
                'cost_centre_id' => $centreId,
                'percentage' => $percentage,
                'fixed_amount' => $fixed,
                'sort_order' => (int) ($line['sort_order'] ?? $index),
            ];
        }

        // Deterministic order: sort_order, then cost_centre_id. The
        // residual always lands on the LAST row of this order.
        usort($clean, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']
            ?: $a['cost_centre_id'] <=> $b['cost_centre_id']);

        // Re-number sort_order densely so the stored order is stable.
        foreach ($clean as $i => &$row) {
            $row['sort_order'] = $i;
        }
        unset($row);

        return $clean;
    }

    protected function validatePercentageSum(array $lines, bool $isReversal = false): void
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = bcadd($sum, $line['percentage'], 6);
        }

        // |sum − 100| must be within tolerance (plan §5: "within a stated
        // tolerance"). 99% and 101% are both rejected. A permitted
        // reversal mirrors the original split, so it sums to −100%.
        $target = $isReversal ? '-100' : '100';
        $diff = bcsub($sum, $target, 6);
        if (bccomp($this->abs($diff), self::SUM_TOLERANCE, 6) > 0) {
            throw new CostCentreException(
                __('Split percentages must add up to :target% (currently :sum%).', ['target' => $target, 'sum' => rtrim(rtrim($sum, '0'), '.')])
            );
        }
    }

    protected function validateFixedSum(string $amount, array $lines, bool $isReversal = false): void
    {
        $sum = '0.00';
        foreach ($lines as $line) {
            $sum = bcadd($sum, $line['fixed_amount'], 2);
        }

        // A permitted reversal mirrors the original split, so its fixed
        // amounts sum to the negated source amount.
        $target = $isReversal ? $this->negate($amount) : $amount;
        if (bccomp($sum, $target, 2) !== 0) {
            throw new CostCentreException(
                __('Fixed split amounts must add up to the source amount (:amount). They currently add up to :sum.', ['amount' => $target, 'sum' => $sum])
            );
        }
    }

    /** Percentage split: round each line, residual to the final line. */
    protected function splitByPercentage(string $amount, array $lines): array
    {
        $computed = [];
        foreach ($lines as $line) {
            // raw = amount * pct / 100 at high precision, then half-up 2dp.
            $raw = bcdiv(bcmul($amount, $line['percentage'], self::SCALE), '100', self::SCALE);
            $computed[] = array_merge($line, ['allocated_amount' => $this->round2($raw)]);
        }

        return $this->applyResidual($amount, $computed);
    }

    /** Fixed split: round each fixed amount, residual to the final line. */
    protected function splitByFixed(string $amount, array $lines): array
    {
        $computed = [];
        foreach ($lines as $line) {
            $computed[] = array_merge($line, ['allocated_amount' => $this->round2($line['fixed_amount'])]);
        }

        return $this->applyResidual($amount, $computed);
    }

    /**
     * Put the rounding residual on the deterministic FINAL eligible line.
     * After this, the allocated amounts sum to exactly the source amount.
     */
    protected function applyResidual(string $amount, array $computed): array
    {
        $sum = '0.00';
        foreach ($computed as $row) {
            $sum = bcadd($sum, $row['allocated_amount'], 2);
        }

        $residual = bcsub($amount, $sum, 2);
        if (bccomp($residual, '0', 2) !== 0) {
            $last = count($computed) - 1;
            $computed[$last]['allocated_amount'] = bcadd($computed[$last]['allocated_amount'], $residual, 2);
            $computed[$last]['rounding_residual'] = $residual;
        }

        return $computed;
    }

    /** Half-up rounding to 2 decimals on a decimal string (sign-aware). */
    public function round2(string $value): string
    {
        $negative = str_starts_with(ltrim($value), '-');
        $abs = $this->abs($value);
        // Add half a minor unit at high precision, then truncate to 2dp.
        $shifted = bcadd($abs, '0.005', self::SCALE);
        $rounded = bcdiv($shifted, '1', 2);

        return $negative ? '-'.$rounded : $rounded;
    }

    protected function normaliseAmount(string $amount): string
    {
        $amount = trim($amount);
        if ($amount === '' || ! is_numeric($amount)) {
            throw new CostCentreException(__('The source amount must be a number.'));
        }

        return $this->round2($amount);
    }

    /** Decimal string or null; rejects non-numeric input. */
    protected function nullOrDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new CostCentreException(__('Split values must be numbers.'));
        }

        return bcadd((string) $value, '0', $scale);
    }

    protected function abs(string $value): string
    {
        return str_starts_with(ltrim($value), '-') ? substr(ltrim($value), 1) : ltrim($value);
    }

    protected function negate(string $value): string
    {
        return str_starts_with(ltrim($value), '-') ? substr(ltrim($value), 1) : '-'.ltrim($value);
    }
}
