<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\ManagementAccountMapping;
use App\Models\ManagementRule;
use Illuminate\Support\Facades\Schema;

/**
 * Manual journals -> Costs aggregation, the second cost source behind
 * the management P&L (the first is Management Documents).
 *
 * A cost with no document (salaries, IRPS, VAT, employee payments)
 * is entered as a double-entry journal posting against a
 * chart-of-accounts expense account. The company maps each such
 * account to a P&L group (+ subgroup) on the Rules page; this service
 * sums the postings per group per month.
 *
 * THE ONE-PLACE-ONLY RULE: only MANUAL journals count (source =
 * 'manual', status = approved). System-posted journals (bills,
 * treasury, fiscal closing, fixed-asset depreciation, ...) are
 * never counted here — a bill that is also entered as a Management
 * Document would otherwise double-count. journal_entries rows
 * written before the `source` column existed have source NULL and
 * are never counted (the safe direction: an old manual journal is
 * left out rather than risking a double-count).
 *
 * Amount of one journal line = debit − credit (a credit reduces
 * the cost). The month comes from the journal's date. Journals have
 * no cost centre, so their amounts appear on the Total and the
 * "No cost centre" Costs views, and as 0 on a single-centre view.
 */
class ManagementJournalCosts
{
    /** Months 1-12, all zero. */
    public static function zeroMonths(): array
    {
        return array_fill(1, 12, 0.0);
    }

    /**
     * Aggregate one company's mapped manual journals for a year.
     *
     * Returns per-month totals per P&L line, the personnel split
     * (one row per mapped account, labelled with the account), and
     * the per-account rows for the Costs tabs.
     */
    public static function forYear(int $creatorId, int $year): array
    {
        $empty = [
            'cogs' => self::zeroMonths(),
            'variables' => self::zeroMonths(),
            'delivery' => self::zeroMonths(),
            'fixed' => self::zeroMonths(),
            'other' => self::zeroMonths(),
            'excluded' => self::zeroMonths(),
            'personnel' => [
                'departments' => [],
                'inss' => self::zeroMonths(),
                'total' => self::zeroMonths(),
            ],
            'groups' => [],
            'accounts' => [],
        ];

        // The table may not exist on installs that have not run the
        // migration yet: checked fresh on every call (never memoized
        // — see the Schema::hasTable lesson).
        if (! Schema::hasTable('management_account_mappings')) {
            return $empty;
        }

        // Without the journal_entries table, or without its `source`
        // column (workflow tests build a minimal journal_entries
        // schema), no journal can be counted as manual: return empty
        // rather than querying a column that does not exist.
        if (! Schema::hasTable('journal_entries') || ! Schema::hasColumn('journal_entries', 'source')) {
            return $empty;
        }

        $mappings = ManagementAccountMapping::where('created_by', $creatorId)
            ->with('account')
            ->get();

        if ($mappings->isEmpty()) {
            return $empty;
        }

        $byAccount = [];
        foreach ($mappings as $mapping) {
            $byAccount[(int) $mapping->chart_account_id] = $mapping;
        }

        // Manual + approved journals of this company, this year.
        $items = JournalItem::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_items.journal')
            ->where('journal_entries.created_by', $creatorId)
            ->where('journal_entries.source', JournalEntry::SOURCE_MANUAL)
            ->where('journal_entries.status', JournalEntry::STATUS_APPROVED)
            ->whereYear('journal_entries.date', $year)
            ->whereIn('journal_items.account', array_keys($byAccount))
            ->get([
                'journal_items.account',
                'journal_items.debit',
                'journal_items.credit',
                'journal_entries.date',
            ]);

        $groupTotals = []; // group => [1..12]
        $accountTotals = []; // account id => [1..12]
        $delivery = self::zeroMonths();
        $departments = []; // account label => [1..12]
        $inss = self::zeroMonths();

        foreach ($items as $item) {
            $mapping = $byAccount[(int) $item->account];
            $month = (int) date('m', strtotime((string) $item->date));
            $amount = round((float) $item->debit - (float) $item->credit, 2);
            if ($amount == 0.0 || $month < 1 || $month > 12) {
                continue;
            }

            $group = $mapping->group;
            $groupTotals[$group][$month] = ($groupTotals[$group][$month] ?? 0.0) + $amount;
            $accountTotals[(int) $item->account][$month] = ($accountTotals[(int) $item->account][$month] ?? 0.0) + $amount;

            if ($group === 'Custos Variáveis' && $mapping->subgroup === ManagementRule::DELIVERY_SUBGROUP) {
                $delivery[$month] += $amount;
            }

            if ($group === 'Pessoal - Remunerações') {
                $label = $mapping->accountLabel();
                $departments[$label][$month] = ($departments[$label][$month] ?? 0.0) + $amount;
            }

            if ($group === 'Pessoal - Impostos') {
                $inss[$month] += $amount;
            }
        }

        $at = fn (string $group) => ManagementDocumentCosts::fullMonths($groupTotals[$group] ?? []);

        $cogs = self::zeroMonths();
        foreach (['CMVM Food', 'CMVM Beverage', 'CMVM Staff Food'] as $g) {
            foreach ($at($g) as $m => $v) {
                $cogs[$m] += $v;
            }
        }

        $excluded = self::zeroMonths();
        foreach (ManagementRule::EXCLUDED_GROUPS as $g) {
            foreach ($at($g) as $m => $v) {
                $excluded[$m] += $v;
            }
        }

        $personnelTotal = self::zeroMonths();
        foreach ($departments as $months) {
            foreach ($months as $m => $v) {
                $personnelTotal[$m] += $v;
            }
        }
        foreach ($inss as $m => $v) {
            $personnelTotal[$m] += $v;
        }

        $groupRows = [];
        foreach (ManagementRule::PL_GROUPS as $group) {
            $months = ManagementDocumentCosts::roundMonths($at($group));
            $groupRows[] = [
                'group' => $group,
                'months' => $months,
                'total' => array_sum($months),
            ];
        }

        $accountRows = [];
        foreach ($mappings as $mapping) {
            $months = ManagementDocumentCosts::roundMonths(
                ManagementDocumentCosts::fullMonths($accountTotals[(int) $mapping->chart_account_id] ?? [])
            );
            $accountRows[] = [
                'chart_account_id' => (int) $mapping->chart_account_id,
                'label' => $mapping->accountLabel(),
                'group' => $mapping->group,
                'subgroup' => $mapping->subgroup,
                'months' => $months,
                'total' => array_sum($months),
                // Journals have no cost centre: they belong to the
                // "No cost centre" view, never to one centre.
                'by_cost_centre' => ['none' => $months],
            ];
        }

        return [
            'cogs' => ManagementDocumentCosts::roundMonths($cogs),
            'variables' => ManagementDocumentCosts::roundMonths($at('Custos Variáveis')),
            'delivery' => ManagementDocumentCosts::roundMonths($delivery),
            'fixed' => ManagementDocumentCosts::roundMonths($at('Custos Fixos')),
            'other' => ManagementDocumentCosts::roundMonths($at('Outros Custos / Pontuais')),
            'excluded' => ManagementDocumentCosts::roundMonths($excluded),
            'personnel' => [
                'departments' => array_map(
                    fn ($m) => ManagementDocumentCosts::roundMonths(ManagementDocumentCosts::fullMonths($m)),
                    $departments
                ),
                'inss' => ManagementDocumentCosts::roundMonths($inss),
                'total' => ManagementDocumentCosts::roundMonths($personnelTotal),
            ],
            'groups' => $groupRows,
            'accounts' => $accountRows,
        ];
    }
}
