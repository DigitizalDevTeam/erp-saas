<?php

namespace App\Services;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;

/**
 * Documents -> Costs aggregation — the SUMIFS engine behind the
 * client's file (PnL2026_Baseline.xlsx).
 *
 * The file's flow: Documentos (one row per expense document, each with
 * a category + a cost centre) -> Rules (category -> P&L group +
 * subgroup) -> Costs tabs (SUMIFS by category x month, and by cost
 * centre) -> PnL -> %PnL.
 *
 * Rules of the aggregation (all from the file):
 *  - Only invoice / credit_note / bank_transfer / manual documents
 *    count. Quotations are stored but EXCLUDED.
 *  - VALOR NET = BASE when present and non-zero, else TOTAL.
 *  - A credit note carries NEGATIVE amounts, so it reduces costs.
 *  - Groups CMVM Food + CMVM Beverage + CMVM Staff Food = F&B.
 *  - Custos Variáveis = variables; the delivery-partner commission
 *    (subgroup "Comissões Delivery", category 6225_01 in the file) is
 *    its own %PnL line, split OUT of VARIABLE.
 *  - Pessoal - Remunerações is split by cost centre (one row per
 *    centre, e.g. "Restaurante (CC 101)"); documents with no centre
 *    go under "Sem centro de custo". Pessoal - Impostos is one row.
 *  - EXCLUIR groups (CapEx / Passivo / Financiamento) never touch
 *    the P&L; they are tracked in the Costs tabs only. TOTAL GERAL
 *    = the 8 P&L groups + unmapped (the file's row 84 formula:
 *    =E5+E13+E18+E21+E26+E33+E37+E64+E82).
 *  - Unmapped categories cannot happen through the UI (a document's
 *    category is a required FK, and a rule in use cannot be deleted),
 *    but the row must still exist in the Costs tabs — it stays 0.
 *
 * NOTE on the file's P&L personnel split: the file's PnL formulas
 * for the three personnel rows do not match their labels (row 14
 * "Operação (CC 101)" actually sums ALL remunerações + ALL impostos,
 * row 15 "Group (CC 102)" actually reads Costs-101 row 26). The
 * build follows the labels instead, as the analysis describes:
 * Operação = remunerações on CC 101, Group = remunerações on CC 102,
 * Impostos = the Impostos group total. The PESSOAL TOTAL is identical
 * either way (all Jan–May remunerações sit on CC 101, impostos are 0
 * in the sample), so the file's verified totals still match.
 */
class ManagementDocumentCosts
{
    /** Months 1-12, all zero. */
    public static function zeroMonths(): array
    {
        return array_fill(1, 12, 0.0);
    }

    /** Fill a partial month map (only months with documents) to 1-12. */
    public static function fullMonths(array $partial): array
    {
        return array_replace(self::zeroMonths(), $partial);
    }

    /** Round every month to 2dp (float sums drift: 213.39999999999998). */
    public static function roundMonths(array $months): array
    {
        return array_map(fn ($v) => round($v, 2), $months);
    }

    /**
     * Aggregate one company's costing documents for a year.
     *
     * Returns per-month totals per P&L line, the personnel split by
     * cost centre, and the Costs-tab rows (per rule, per group, and
     * the no-cost-centre / unmapped rows), for the Total view and for
     * each cost centre.
     */
    public static function forYear(int $creatorId, int $year): array
    {
        ManagementRule::ensureSeeded($creatorId);
        ManagementCostCentre::ensureSeeded($creatorId);

        $rules = ManagementRule::where('created_by', $creatorId)
            ->orderBy('sort_order')
            ->get();

        $centres = ManagementCostCentre::where('created_by', $creatorId)
            ->orderBy('code')
            ->get();

        $docs = ManagementDocument::where('created_by', $creatorId)
            ->whereYear('doc_date', $year)
            ->whereIn('doc_type', ManagementDocument::COSTING_TYPES)
            ->with(['rule', 'costCentre'])
            ->get();

        // --- Accumulators -------------------------------------------------
        $groupTotals = []; // group => [1..12]
        $ruleTotals = [];  // rule id => [1..12]
        $ruleCcTotals = []; // rule id => cc key ('none' or centre id) => [1..12]
        $groupCcTotals = []; // group => cc key => [1..12]
        $noCcTotals = self::zeroMonths();
        $delivery = self::zeroMonths();
        $departments = []; // '{name} (CC {code})' | 'Sem centro de custo' => [1..12]
        $inss = self::zeroMonths();

        foreach ($docs as $doc) {
            $month = $doc->month();
            $net = $doc->netValue();
            $group = $doc->plGroup() ?? '⚠ NÃO MAPEADA';
            $ccKey = $doc->management_cost_centre_id ?? 'none';

            $groupTotals[$group][$month] = ($groupTotals[$group][$month] ?? 0.0) + $net;
            $ruleTotals[$doc->management_rule_id][$month] = ($ruleTotals[$doc->management_rule_id][$month] ?? 0.0) + $net;
            $ruleCcTotals[$doc->management_rule_id][$ccKey][$month] = ($ruleCcTotals[$doc->management_rule_id][$ccKey][$month] ?? 0.0) + $net;
            $groupCcTotals[$group][$ccKey][$month] = ($groupCcTotals[$group][$ccKey][$month] ?? 0.0) + $net;

            if ($ccKey === 'none') {
                $noCcTotals[$month] += $net;
            }

            if ($doc->rule && $doc->rule->subgroup === ManagementRule::DELIVERY_SUBGROUP) {
                $delivery[$month] += $net;
            }

            if ($group === 'Pessoal - Remunerações') {
                $label = $doc->costCentre
                    ? "{$doc->costCentre->name} (CC {$doc->costCentre->code})"
                    : 'Sem centro de custo';
                $departments[$label][$month] = ($departments[$label][$month] ?? 0.0) + $net;
            }

            if ($group === 'Pessoal - Impostos') {
                $inss[$month] += $net;
            }
        }

        // --- P&L lines (company-wide, all cost centres) -------------------
        $at = fn (string $group) => self::fullMonths($groupTotals[$group] ?? []);

        $cogs = self::zeroMonths();
        foreach (['CMVM Food', 'CMVM Beverage', 'CMVM Staff Food'] as $g) {
            foreach ($at($g) as $m => $v) {
                $cogs[$m] += $v;
            }
        }

        $variables = $at('Custos Variáveis');
        $fixed = $at('Custos Fixos');
        $other = $at('Outros Custos / Pontuais');
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

        // --- Costs-tab rows ------------------------------------------------
        // One row per rule (all rules, even with no documents — the
        // file lists every category), one subtotal row per group.
        $ruleRows = [];
        foreach ($rules as $rule) {
            $months = self::roundMonths(self::fullMonths($ruleTotals[$rule->id] ?? []));
            $ruleRows[] = [
                'rule_id' => $rule->id,
                'code' => $rule->code,
                'name' => $rule->name,
                'label' => $rule->label(),
                'group' => $rule->group,
                'subgroup' => $rule->subgroup,
                'sort_order' => $rule->sort_order,
                'months' => $months,
                'total' => array_sum($months),
                // Per-centre split, for the one-view-per-centre tabs.
                'by_cost_centre' => $ruleCcTotals[$rule->id] ?? [],
            ];
        }

        $groupRows = [];
        foreach (ManagementRule::PL_GROUPS as $group) {
            $months = self::roundMonths($at($group));
            $groupRows[] = [
                'group' => $group,
                'months' => $months,
                'total' => array_sum($months),
                'by_cost_centre' => $groupCcTotals[$group] ?? [],
            ];
        }

        $totalGeral = self::zeroMonths();
        foreach (array_merge($groupRows, [['months' => $at('⚠ NÃO MAPEADA')]]) as $row) {
            if (in_array($row['group'] ?? '', ManagementRule::EXCLUDED_GROUPS, true)) {
                continue;
            }
            foreach ($row['months'] as $m => $v) {
                $totalGeral[$m] += $v;
            }
        }

        return [
            'year' => $year,
            'creator_id' => $creatorId,
            // P&L lines.
            'cogs' => self::roundMonths($cogs),
            'variables' => self::roundMonths($variables),
            'delivery' => self::roundMonths($delivery),
            'fixed' => self::roundMonths($fixed),
            'other' => self::roundMonths($other),
            'excluded' => self::roundMonths($excluded),
            'personnel' => [
                'departments' => array_map(fn ($m) => self::roundMonths(self::fullMonths($m)), $departments),
                'inss' => self::roundMonths($inss),
                'total' => self::roundMonths($personnelTotal),
            ],
            // Costs tabs.
            'rules' => $ruleRows,
            'groups' => $groupRows,
            'total_geral' => self::roundMonths($totalGeral),
            'no_cost_centre' => self::roundMonths($noCcTotals),
            'unmapped' => self::zeroMonths(),
            'cost_centres' => $centres->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'label' => $c->label(),
            ])->all(),
        ];
    }

    /**
     * One Costs tab: the file's shape — rows = categories (with group
     * subtotal rows), months Jan–Dez + TOTAL, TOTAL GERAL row, plus the
     * no-cost-centre and unmapped rows.
     *
     * $costCentre: 'all' (Costs-Total), a centre id (Costs-101...),
     * or 'none' (only documents without a cost centre).
     */
    public static function costsTable(int $creatorId, int $year, $costCentre = 'all'): array
    {
        $data = self::forYear($creatorId, $year);
        $pick = function (array $months, array $byCc) use ($costCentre): array {
            if ($costCentre === 'all') {
                return $months;
            }
            $key = $costCentre === 'none' ? 'none' : (int) $costCentre;

            return self::fullMonths($byCc[$key] ?? []);
        };

        $rows = [];
        foreach ($data['rules'] as $rule) {
            $months = $pick($rule['months'], $rule['by_cost_centre']);
            $rows[] = [
                'label' => $rule['label'],
                'subgroup' => $rule['subgroup'],
                'group' => $rule['group'],
                'months' => $months,
                'total' => array_sum($months),
                'is_subtotal' => false,
            ];
        }

        // Group subtotal rows, interleaved in the file's order (the
        // file puts each subtotal above its categories; here we append
        // them after the category rows — the UI groups by P&L group).
        $subtotals = [];
        foreach ($data['groups'] as $group) {
            $months = $pick($group['months'], $group['by_cost_centre']);
            $subtotals[] = [
                'label' => $group['group'],
                'subgroup' => '',
                'group' => $group['group'],
                'months' => $months,
                'total' => array_sum($months),
                'is_subtotal' => true,
            ];
        }

        // TOTAL GERAL: the 8 P&L groups + unmapped (never EXCLUIR).
        $totalGeral = self::zeroMonths();
        foreach ($data['groups'] as $group) {
            if (in_array($group['group'], ManagementRule::EXCLUDED_GROUPS, true)) {
                continue;
            }
            $months = $pick($group['months'], $group['by_cost_centre']);
            foreach ($months as $m => $v) {
                $totalGeral[$m] += $v;
            }
        }
        // Unmapped is 0 by construction (FK-enforced), but the row
        // must exist; it adds 0 to TOTAL GERAL either way.
        foreach ($data['unmapped'] as $m => $v) {
            $totalGeral[$m] += $v;
        }

        // No-cost-centre row: only meaningful on the Total view (on a
        // per-centre view it is 0 by definition).
        $noCc = $costCentre === 'all' ? $data['no_cost_centre'] : self::zeroMonths();

        return [
            'year' => $year,
            'cost_centre' => $costCentre,
            'cost_centres' => $data['cost_centres'],
            'rows' => $rows,
            'subtotals' => $subtotals,
            'total_geral' => $totalGeral,
            'no_cost_centre' => $noCc,
            'unmapped' => $data['unmapped'],
        ];
    }
}
