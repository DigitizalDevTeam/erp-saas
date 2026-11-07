<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Contract Management Phase 1 — billing occurrence math (plan §2,
 * §6, AC01/AC04/AC05/AC10).
 *
 * Periods are anchored on the contract's stored anchor day and
 * clamped to the month length — 31 Jan -> 28 Feb -> 31 Mar in
 * 2027; 29 Feb stays 29 Feb in leap years. The anchor is stored
 * once and never recomputed from the last issue date (plan §11
 * risk 7): a late/catch-up run never shifts future invoices.
 *
 * Coverage is stored start-inclusive / end-exclusive; the invoice
 * shows the inclusive final day. Advance bills at period start,
 * arrears at period end; due = billing date + payment terms.
 *
 * A period is partial when its coverage overlaps the contract
 * term for fewer days than the full period. The contract's
 * partial-period rule decides: 'full' bills the whole period
 * amount, 'prorate' bills covered days / full days, 'skip'
 * creates no occurrence for it.
 *
 * Generation is idempotent: occurrence_key is deterministic
 * ({reference}:{period}:invoice) and unique per company +
 * contract, so a second run creates nothing new (firstOrCreate
 * + the unique index as the second guard).
 *
 * All money math is bcmath, never float. Amounts are
 * non-negative, so bcRound4() (round half up) is safe here.
 */
class BillingOccurrenceService
{
    /** Months ahead an open-ended contract generates (re-runnable). */
    public const OPEN_ENDED_WINDOW_MONTHS = 12;

    /**
     * Periods [start, end) for a contract inside [$from, $to],
     * anchored on the stored anchor day. $to is inclusive on
     * period start: a period starting exactly on $to is included.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public function periods(BillingContract $contract, Carbon $from, Carbon $to): array
    {
        $start = Carbon::parse($contract->start_date)->startOfDay();
        $end = $contract->end_date ? Carbon::parse($contract->end_date)->startOfDay() : null;

        if ($contract->billing_frequency === BillingContract::FREQUENCY_CUSTOM) {
            return $this->customPeriods($contract, $start, $from, $to);
        }

        $step = match ($contract->billing_frequency) {
            BillingContract::FREQUENCY_MONTHLY => 1,
            BillingContract::FREQUENCY_QUARTERLY => 3,
            BillingContract::FREQUENCY_SEMI_ANNUAL => 6,
            BillingContract::FREQUENCY_ANNUAL => 12,
            default => throw new BillingContractException('Unknown billing frequency: ' . $contract->billing_frequency),
        };

        $anchorDay = (int) $contract->anchor_day;

        // Anchor dates from the month containing the start date.
        $anchors = [];
        $cursor = $start->copy()->startOfMonth();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($to) && $guard < 1200) {
            $guard++;
            $day = min($anchorDay, $cursor->daysInMonth);
            $anchors[] = $cursor->copy()->day($day)->startOfDay();
            $cursor = $cursor->copy()->addMonthsNoOverflow($step)->startOfMonth();
        }
        // One extra anchor so the last period has an end.
        $day = min($anchorDay, $cursor->daysInMonth);
        $anchors[] = $cursor->copy()->day($day)->startOfDay();

        $periods = [];
        foreach ($anchors as $i => $anchor) {
            if (! isset($anchors[$i + 1])) {
                break;
            }
            $periodStart = $anchor;
            $periodEnd = $anchors[$i + 1];
            // Drop periods entirely before the contract starts or
            // entirely after it ends; keep a period that overlaps.
            if ($periodEnd->lessThanOrEqualTo($start)) {
                continue;
            }
            if ($end !== null && $periodStart->greaterThan($end)) {
                continue;
            }
            // Window filter is on overlap, not on period start: the
            // first period may start before $from (a partial first
            // period) and must still be returned.
            if ($periodEnd->lessThanOrEqualTo($from) || $periodStart->greaterThan($to)) {
                continue;
            }
            $periods[] = [$periodStart, $periodEnd];
        }

        return $periods;
    }

    /**
     * Custom frequency: step from the start date by N days / weeks /
     * months / years. The anchor day does not apply here.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function customPeriods(BillingContract $contract, Carbon $start, Carbon $from, Carbon $to): array
    {
        $interval = max(1, (int) ($contract->custom_interval ?? 1));
        $unit = $contract->custom_unit ?? 'months';
        $end = $contract->end_date ? Carbon::parse($contract->end_date)->startOfDay() : null;

        $step = function (Carbon $date) use ($interval, $unit): Carbon {
            return match ($unit) {
                'days' => $date->copy()->addDays($interval),
                'weeks' => $date->copy()->addWeeks($interval),
                'years' => $date->copy()->addYearsNoOverflow($interval),
                default => $date->copy()->addMonthsNoOverflow($interval),
            };
        };

        $points = [];
        $cursor = $start->copy();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($to) && $guard < 3700) {
            $guard++;
            $points[] = $cursor->copy();
            $cursor = $step($cursor);
        }
        $points[] = $cursor->copy();

        $periods = [];
        foreach ($points as $i => $point) {
            if (! isset($points[$i + 1])) {
                break;
            }
            $periodStart = $point;
            $periodEnd = $points[$i + 1];
            if ($end !== null && $periodStart->greaterThan($end)) {
                continue;
            }
            if ($periodEnd->lessThanOrEqualTo($from) || $periodStart->greaterThan($to)) {
                continue;
            }
            $periods[] = [$periodStart, $periodEnd];
        }

        return $periods;
    }

    /**
     * The last date occurrences are generated to: the contract end
     * date, or 12 months from today for an open-ended contract
     * (re-running the generator extends the window).
     */
    public function generationEnd(BillingContract $contract): Carbon
    {
        if ($contract->end_date) {
            return Carbon::parse($contract->end_date)->startOfDay();
        }

        return Carbon::today()->addMonths(self::OPEN_ENDED_WINDOW_MONTHS)->startOfDay();
    }

    /** Period total for one billing period (sum of the lines). */
    public function contractPeriodTotal(BillingContract $contract): string
    {
        $total = '0.0000';
        foreach ($contract->lines as $line) {
            $total = bcadd($total, (string) $line->amount, 4);
        }

        return $total;
    }

    /** Deterministic period key per contract + period start. */
    public function periodKey(BillingContract $contract, Carbon $date): string
    {
        return match ($contract->billing_frequency) {
            BillingContract::FREQUENCY_MONTHLY => $date->format('Y-m'),
            BillingContract::FREQUENCY_QUARTERLY => $date->format('Y') . '-Q' . (int) ceil(((int) $date->format('m')) / 3),
            BillingContract::FREQUENCY_SEMI_ANNUAL => $date->format('Y') . '-H' . (((int) $date->format('m')) <= 6 ? 1 : 2),
            BillingContract::FREQUENCY_ANNUAL => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    /** Deterministic occurrence key: {reference}:{period}:{purpose}. */
    public function occurrenceKey(BillingContract $contract, string $periodKey, string $purpose = 'invoice'): string
    {
        return $contract->reference . ':' . $periodKey . ':' . $purpose;
    }

    /**
     * Coverage of one period against the contract term.
     *
     * Phase 2: the invoice service needs the same numbers to scale
     * each contract line for a prorated period, so this is the one
     * place the overlap is computed. occurrenceForPeriod() uses it
     * for the occurrence amount; BillingInvoiceService uses it for
     * the per-line amounts. Both must agree, or the invoice total
     * would not match the occurrence amount.
     *
     * @return array{full_days: int, covered_days: int, partial: bool, ratio: string}
     *         ratio = covered / full as a 6-decimal string ('1.000000' when whole).
     */
    public function periodCoverage(BillingContract $contract, Carbon $periodStart, Carbon $periodEnd): array
    {
        $start = Carbon::parse($contract->start_date)->startOfDay();
        $end = $contract->end_date ? Carbon::parse($contract->end_date)->startOfDay()->addDay() : null;

        $fullDays = (int) $periodStart->diffInDays($periodEnd);
        $coverStart = $periodStart->greaterThan($start) ? $periodStart->copy() : $start->copy();
        $coverEnd = $end !== null && $periodEnd->greaterThan($end) ? $end->copy() : $periodEnd->copy();
        $coveredDays = $coverStart->lessThan($coverEnd) ? (int) $coverStart->diffInDays($coverEnd) : 0;
        $partial = $coveredDays < $fullDays;

        return [
            'full_days' => $fullDays,
            'covered_days' => $coveredDays,
            'partial' => $partial,
            'ratio' => ($fullDays > 0 && $coveredDays > 0)
                ? bcdiv((string) $coveredDays, (string) $fullDays, 6)
                : '0.000000',
        ];
    }

    /**
     * Occurrence data for one period, or null when the contract's
     * partial-period rule says to skip it. The amount already
     * reflects the rule (full / prorated).
     *
     * @return array<string, mixed>|null
     */
    public function occurrenceForPeriod(BillingContract $contract, Carbon $periodStart, Carbon $periodEnd): ?array
    {
        $start = Carbon::parse($contract->start_date)->startOfDay();
        $end = $contract->end_date ? Carbon::parse($contract->end_date)->startOfDay()->addDay() : null;

        $coverage = $this->periodCoverage($contract, $periodStart, $periodEnd);
        $fullDays = $coverage['full_days'];
        $coveredDays = $coverage['covered_days'];
        $partial = $coverage['partial'];

        if ($fullDays <= 0 || $coveredDays <= 0) {
            return null;
        }

        $total = $this->contractPeriodTotal($contract);

        if ($partial && $contract->partial_period_rule === BillingContract::PARTIAL_SKIP) {
            return null;
        }

        if ($partial && $contract->partial_period_rule === BillingContract::PARTIAL_PRORATE) {
            // Same formula as Phase 1 (do NOT pre-round the ratio:
            // total x covered / full at 6dp, then round to 4dp).
            $amount = $this->bcRound4(bcdiv(bcmul($total, (string) $coveredDays, 6), (string) $fullDays, 6));
        } else {
            $amount = $total;
        }

        $billingDate = $contract->billing_mode === BillingContract::BILLING_MODE_ARREARS
            ? $periodEnd->copy()
            : $periodStart->copy();

        // An advance invoice is never issued before the contract
        // starts, even when the anchor period starts earlier.
        if ($billingDate->lessThan($start)) {
            $billingDate = $start->copy();
        }

        $dueDate = $billingDate->copy()->addDays(max(0, (int) $contract->payment_terms_days));
        $periodKey = $this->periodKey($contract, $periodStart);

        return [
            'period_key' => $periodKey,
            'occurrence_key' => $this->occurrenceKey($contract, $periodKey),
            'period_start' => $periodStart->copy(),
            'period_end' => $periodEnd->copy(),
            'billing_date' => $billingDate,
            'due_date' => $dueDate,
            'amount' => $amount,
            'currency' => $contract->currency,
            'partial' => $partial,
            'covered_days' => $coveredDays,
            'full_days' => $fullDays,
        ];
    }

    /**
     * Generate occurrences for a contract inside [$from, $to]
     * (defaults: whole term, or the open-ended window). Idempotent:
     * a second run creates nothing new.
     *
     * @return array{generated: int, skipped: int}
     */
    public function generateForContract(BillingContract $contract, int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
            throw new BillingContractException('Occurrences can only be generated for an active or suspended contract.');
        }

        $from = $from ?? Carbon::parse($contract->start_date)->startOfDay();
        $to = $to ?? $this->generationEnd($contract);

        $generated = 0;
        $skipped = 0;

        foreach ($this->periods($contract, $from, $to) as [$periodStart, $periodEnd]) {
            $data = $this->occurrenceForPeriod($contract, $periodStart, $periodEnd);
            if ($data === null) {
                $skipped++;
                continue;
            }

            try {
                $created = BillingContractOccurrence::firstOrCreate(
                    [
                        'created_by' => (int) $contract->created_by,
                        'contract_id' => $contract->id,
                        'occurrence_key' => $data['occurrence_key'],
                    ],
                    [
                        'period_key' => $data['period_key'],
                        'period_start' => $data['period_start']->format('Y-m-d'),
                        'period_end' => $data['period_end']->format('Y-m-d'),
                        'billing_date' => $data['billing_date']->format('Y-m-d'),
                        'due_date' => $data['due_date']->format('Y-m-d'),
                        'amount' => $data['amount'],
                        'currency' => $data['currency'],
                        'state' => BillingContractOccurrence::STATE_PENDING,
                        'purpose' => 'invoice',
                        'generated_at' => now(),
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $generated++;
                } else {
                    $skipped++;
                }
            } catch (QueryException $e) {
                // Lost a race with another generator run: the unique
                // index already holds this occurrence.
                $skipped++;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * After an amendment: drop pending occurrences starting on or
     * after the effective date and regenerate them under the new
     * terms. Pending occurrences before the effective date, and
     * generated/invoiced ones, are never touched.
     */
    public function regenerateFuturePending(BillingContract $contract, Carbon $effectiveDate, int $userId): array
    {
        BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('period_start', '>=', $effectiveDate->format('Y-m-d'))
            ->delete();

        return $this->generateForContract($contract, $userId, $effectiveDate->copy(), $this->generationEnd($contract));
    }

    /**
     * On end/cancel: cancel pending occurrences starting on or
     * after the effective date, and re-price the one straddling
     * period (if any) to the days actually covered before the
     * effective date. Returns the number of occurrences cancelled.
     */
    public function cancelPendingFrom(BillingContract $contract, Carbon $effectiveDate, int $userId, string $reason): int
    {
        $effective = $effectiveDate->copy()->startOfDay();

        $cancelled = BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('period_start', '>=', $effective->format('Y-m-d'))
            ->update(['state' => BillingContractOccurrence::STATE_CANCELLED]);

        // Straddling period: it started before the effective date
        // but covers days after it. Re-price it to the days actually
        // covered before the effective date, against the FULL
        // period length — never against the truncated length.
        $straddling = BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('period_start', '<', $effective->format('Y-m-d'))
            ->whereDate('period_end', '>', $effective->format('Y-m-d'))
            ->first();

        if ($straddling !== null) {
            $periodStart = Carbon::parse($straddling->period_start)->startOfDay();
            $periodEnd = Carbon::parse($straddling->period_end)->startOfDay();
            $fullDays = $periodStart->diffInDays($periodEnd);
            $coveredDays = $periodStart->diffInDays($effective);

            if ($coveredDays <= 0 || $contract->partial_period_rule === BillingContract::PARTIAL_SKIP) {
                $straddling->update([
                    'state' => BillingContractOccurrence::STATE_SKIPPED,
                    'skip_reason' => $reason,
                ]);
            } else {
                $updates = ['period_end' => $effective->format('Y-m-d')];

                if ($contract->partial_period_rule === BillingContract::PARTIAL_PRORATE && $fullDays > 0) {
                    $total = $this->contractPeriodTotal($contract);
                    $updates['amount'] = $this->bcRound4(
                        bcdiv(bcmul($total, (string) $coveredDays, 6), (string) $fullDays, 6)
                    );
                }

                // Arrears bills at period end: the period now ends
                // on the effective date, so the billing date moves
                // with it (the invoice is for service before
                // termination and may still be issued later).
                if ($contract->billing_mode === BillingContract::BILLING_MODE_ARREARS) {
                    $updates['billing_date'] = $effective->format('Y-m-d');
                    $updates['due_date'] = $effective->copy()
                        ->addDays(max(0, (int) $contract->payment_terms_days))
                        ->format('Y-m-d');
                }

                $straddling->update($updates);
            }
        }

        return (int) $cancelled;
    }

    /**
     * On resume with the 'skip' decision: pending occurrences whose
     * billing date fell inside the suspension are skipped, so the
     * customer is never billed for the suspended time.
     */
    public function markMissedSkipped(BillingContract $contract, Carbon $from, Carbon $to, int $userId, string $reason): int
    {
        return (int) BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('billing_date', '>=', $from->format('Y-m-d'))
            ->whereDate('billing_date', '<=', $to->format('Y-m-d'))
            ->update([
                'state' => BillingContractOccurrence::STATE_SKIPPED,
                'skip_reason' => $reason,
            ]);
    }

    /**
     * Round a non-negative bcmath string to 4 decimals, half up.
     * Amounts in this module are never negative.
     */
    public function bcRound4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }
}
