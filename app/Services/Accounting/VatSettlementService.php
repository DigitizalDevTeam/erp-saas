<?php

namespace App\Services\Accounting;

/**
 * ICMS / VAT settlement helper — posts standard entries when accounts exist.
 * Amounts are entered by the accountant (manual).
 */
class VatSettlementService
{
    public function __construct(
        private ChartAccountResolver $resolver,
        private JournalEntryPoster $poster
    ) {}

    /**
     * @param  array{inv?: float, tang?: float, srv?: float, collected?: float, to_payable?: float, to_recover?: float}  $amounts
     * @return array{success: bool, message: string, journal_ids: array<int, int>}
     */
    public function run(int $createdBy, int $fiscalYear, string $date, int $accountingPeriod, array $amounts): array
    {
        $codes = config('fiscal_closing.accounts');
        $id4435 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_settlement']);
        $id44321 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_deductible_inventories']);
        $id44322 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_deductible_tangible']);
        $id44323 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_deductible_services']);
        $id44331 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_collected']);
        $id4437 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_payable']);
        $id4438 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['vat_recoverable']);

        if (! $id4435) {
            return ['success' => false, 'message' => __('Account 4435 (VAT settlement) not found in chart.'), 'journal_ids' => []];
        }

        $journalIds = [];
        $refBase = 'FY-'.$fiscalYear.'-VAT';

        $inv = round((float) ($amounts['inv'] ?? 0), 2);
        $tang = round((float) ($amounts['tang'] ?? 0), 2);
        $srv = round((float) ($amounts['srv'] ?? 0), 2);
        $sumDed = $inv + $tang + $srv;

        if ($sumDed > 0.009) {
            $lines = [
                ['account' => $id4435, 'debit' => $sumDed, 'credit' => 0, 'description' => __('Deductible VAT to settlement')],
            ];
            if ($inv > 0.009) {
                if (! $id44321) {
                    return ['success' => false, 'message' => __('Account 44321 not found (inventories VAT).'), 'journal_ids' => []];
                }
                $lines[] = ['account' => $id44321, 'debit' => 0, 'credit' => $inv, 'description' => __('Inventories')];
            }
            if ($tang > 0.009) {
                if (! $id44322) {
                    return ['success' => false, 'message' => __('Account 44322 not found (tangible assets VAT).'), 'journal_ids' => []];
                }
                $lines[] = ['account' => $id44322, 'debit' => 0, 'credit' => $tang, 'description' => __('Tangible assets')];
            }
            if ($srv > 0.009) {
                if (! $id44323) {
                    return ['success' => false, 'message' => __('Account 44323 not found (services VAT).'), 'journal_ids' => []];
                }
                $lines[] = ['account' => $id44323, 'debit' => 0, 'credit' => $srv, 'description' => __('Other goods and services')];
            }
            $j = $this->poster->post($createdBy, $date, $refBase.'-DED', __('VAT — deductible to 4435'), $lines, $accountingPeriod);
            $journalIds[] = (int) $j->id;
        }

        $collected = round((float) ($amounts['collected'] ?? 0), 2);
        if ($collected > 0.009) {
            if (! $id44331) {
                return ['success' => false, 'message' => __('Account 44331 (ICMS collected) not found.'), 'journal_ids' => $journalIds];
            }
            $j = $this->poster->post(
                $createdBy,
                $date,
                $refBase.'-COL',
                __('VAT — collected to settlement'),
                [
                    ['account' => $id44331, 'debit' => $collected, 'credit' => 0, 'description' => __('ICMS collected')],
                    ['account' => $id4435, 'debit' => 0, 'credit' => $collected, 'description' => __('ICMS collected')],
                ],
                $accountingPeriod
            );
            $journalIds[] = (int) $j->id;
        }

        $toPay = round((float) ($amounts['to_payable'] ?? 0), 2);
        if ($toPay > 0.009) {
            if (! $id4437) {
                return ['success' => false, 'message' => __('Account 4437 (ICMS payable) not found.'), 'journal_ids' => $journalIds];
            }
            $j = $this->poster->post(
                $createdBy,
                $date,
                $refBase.'-PAY',
                __('VAT — payable'),
                [
                    ['account' => $id4435, 'debit' => $toPay, 'credit' => 0, 'description' => __('VAT payable')],
                    ['account' => $id4437, 'debit' => 0, 'credit' => $toPay, 'description' => __('VAT payable')],
                ],
                $accountingPeriod
            );
            $journalIds[] = (int) $j->id;
        }

        $toRec = round((float) ($amounts['to_recover'] ?? 0), 2);
        if ($toRec > 0.009) {
            if (! $id4438) {
                return ['success' => false, 'message' => __('Account 4438 (ICMS to recover) not found.'), 'journal_ids' => $journalIds];
            }
            $j = $this->poster->post(
                $createdBy,
                $date,
                $refBase.'-REC',
                __('VAT — recoverable'),
                [
                    ['account' => $id4438, 'debit' => $toRec, 'credit' => 0, 'description' => __('ICMS to recover')],
                    ['account' => $id4435, 'debit' => 0, 'credit' => $toRec, 'description' => __('ICMS to recover')],
                ],
                $accountingPeriod
            );
            $journalIds[] = (int) $j->id;
        }

        if (count($journalIds) === 0) {
            return ['success' => false, 'message' => __('Enter at least one non-zero VAT amount.'), 'journal_ids' => []];
        }

        return ['success' => true, 'message' => __('VAT settlement journals posted.'), 'journal_ids' => $journalIds];
    }
}
