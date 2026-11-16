<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\Invoice;
use App\Models\ManagementReportHistory;
use App\Models\Pos;
use App\Models\Tax;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly management dataset behind the management P&L reports
 * (P&L (Management), %P&L (Management)) — see
 * ~/workspace/your_files/erpsaaspro-management-reports-analysis.md
 * for the sheet analysis and the mapping decisions.
 *
 * One builder feeds both reports so the numbers can never disagree
 * between them. All amounts are plain floats, rounded to 2dp per month;
 * totals are sums of the rounded months.
 *
 * REVENUE (ex-VAT) per month = issued invoices, recognised at issue:
 * status Sent (1) / Unpaid (2) / Partially Paid (3) / Paid (4), and
 * NOT internal-consumption invoices (document_type =
 * 'internal_consumption' — those are #INT expense documents, never a
 * sale; see Invoice::isInternal()). Draft (0), Cancelled (5) and
 * Pending Authorization (6) are NOT revenue.
 *
 * POS sales ARE invoices in this app (each completed POS sale creates
 * an invoice with pos_id set), so there is no separate POS revenue
 * stream — counting invoices already counts POS, and there is nothing
 * to double-count. Open POS orders (a Pos row with no linked invoice)
 * are NOT revenue; they are listed under CONTROLOS instead.
 *
 * Per line: ex = price x qty − discount (discount is a per-line total
 * in this app — see Invoice::getTotalDiscount()); IVA = ex x the
 * line's tax rates (the comma-separated tax ids on the line, same
 * arithmetic as the sales report's total_tax). Total = ex + IVA holds
 * exactly. The tax math runs in PHP, not in FIND_IN_SET SQL, so the
 * same code runs on MySQL and on SQLite (tests).
 *
 * COSTS come from Management Documents (Documentos) — the client's
 * real expense documents, each with a category (Rules) and a cost
 * centre — aggregated by ManagementDocumentCosts (the file's SUMIFS),
 * PLUS mapped manual journals (a cost with no document is a
 * double-entry posting against a chart-of-accounts expense account;
 * see ManagementJournalCosts for the one-place-only rule). F&B COST
 * = CMVM Food + Beverage + Staff Food groups; VARIAVEIS =
 * Custos Variáveis; PESSOAL = Pessoal - Remunerações split by cost
 * centre + Pessoal - Impostos; FIXOS = Custos Fixos; OUTROS =
 * Outros Custos / Pontuais. DEPRECIAÇÕES come from the Fixed Assets
 * module (posted depreciation entries, per month); AMORTIZAÇÕES have
 * no system source and read 0 unless history supplies them.
 *
 * There are NO other system-derived costs here on purpose: F&B from
 * product purchase prices (recipe-based food cost is a flawed method
 * — the client and Rui both said trust the documents), PESSOAL from
 * payslips, FIXOS from the Expenses module. Counting both the
 * documents AND the system sources would double-count the same cost.
 * Sales stay automatic (invoices) — there is no manual sales entry.
 *
 * SALES CHANNELS (the file's Sales tab): Software Faturação is the
 * total (= this revenue). Restaurante = restaurant-branch POS sales
 * not on a Bar table area; Bar = restaurant-branch POS sales on a
 * table in an area named Bar; Eventos has no system source (0);
 * Outras = the rest (direct invoices + retail POS), computed as the
 * residual so the channels always sum to the total. Channel history
 * rows (channel:{name}:ex|iva) win per channel-month, like the
 * other history lines.
 *
 * Historical data: months the client supplied in their Excel file live
 * in management_report_history (one row per line value). When history
 * exists for a (month, line) it REPLACES the system value for that line
 * — the imported month is the source of truth, so system documents in
 * that month can never double-count with it. Months without history
 * stay fully automatic. VARIAVEIS, OUTROS/PONTUAIS, DEPRECIAÇÕES and
 * AMORTIZAÇÕES have no system source, so they read 0 unless history
 * supplies them. Pass $applyHistory = false to get the pure system
 * dataset.
 */
class MonthlyManagementData
{
    /** Months 1..12 of zeroed revenue buckets. */
    private static function emptyRevenue(): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['ex' => 0.0, 'iva' => 0.0, 'total' => 0.0];
        }

        return $out;
    }

    /**
     * @return array{year: int, revenue: array, priorRevenue: array, cogs: array<int, float>, personnel: array, departments: list<string>, expenses: array<int, float>, excluded: array, variables: array<int, float>, delivery: array<int, float>, other: array<int, float>, depreciation: array<int, float>, amortization: array<int, float>, historyMonths: list<int>}
     */
    public static function build(int $creatorId, int $year, bool $applyHistory = true): array
    {
        $revenue = self::emptyRevenue();
        $priorRevenue = self::emptyRevenue();
        $cogs = array_fill(1, 12, 0.0);
        $expenses = array_fill(1, 12, 0.0);
        $variables = array_fill(1, 12, 0.0);
        $delivery = array_fill(1, 12, 0.0);
        $other = array_fill(1, 12, 0.0);
        $depreciation = array_fill(1, 12, 0.0);
        $amortization = array_fill(1, 12, 0.0);
        $personnel = [];
        for ($m = 1; $m <= 12; $m++) {
            $personnel[$m] = ['departments' => [], 'inss' => 0.0, 'total' => 0.0];
        }
        $excluded = [
            'draftInvoices' => [],
            'openOrders' => [],
        ];
        for ($m = 1; $m <= 12; $m++) {
            $excluded['draftInvoices'][$m] = ['count' => 0, 'ex' => 0.0];
            $excluded['openOrders'][$m] = ['count' => 0, 'total' => 0.0];
        }

        $monthOf = static fn ($d): ?int => $d ? (int) date('m', strtotime((string) $d)) : null;
        $yearOf = static fn ($d): ?int => $d ? (int) date('Y', strtotime((string) $d)) : null;

        // Sales channels (the file's Sales tab): Restaurante / Bar /
        // Eventos are POS outlets; Outras is the residual (direct
        // invoices + retail POS) so the channels always sum to the
        // total; Software Faturação is the total itself.
        $channelNames = ['Restaurante', 'Bar', 'Eventos', 'Outras'];
        $channels = [];
        foreach ($channelNames as $name) {
            for ($m = 1; $m <= 12; $m++) {
                $channels[$name][$m] = ['ex' => 0.0, 'iva' => 0.0, 'total' => 0.0];
            }
        }
        $posChannel = [];
        foreach (Pos::where('created_by', $creatorId)
            ->where(function ($q) use ($year) {
                $q->whereYear('pos_date', $year)->orWhereYear('pos_date', $year - 1);
            })
            ->with(['branch', 'table.area'])
            ->get(['id', 'branch_id', 'table_id']) as $pos) {
            $posChannel[$pos->id] = self::posChannelName($pos);
        }

        // Tax id -> rate, like Utility::getTaxData() (unscoped, as there).
        $rates = Tax::pluck('rate', 'id')->map(fn ($r) => (float) $r)->all();

        // --- Invoices (this year + prior year) -------------------------
        // Revenue is recognised at issue: every status EXCEPT Draft (0),
        // Cancelled (5) and Pending Authorization (6). Internal
        // consumption (#INT) is an expense document, never a sale.
        $invoices = Invoice::where('created_by', $creatorId)
            ->where(function ($q) use ($year) {
                $q->whereYear('issue_date', $year)->orWhereYear('issue_date', $year - 1);
            })
            ->with('items:id,invoice_id,price,quantity,discount,tax')
            ->get(['id', 'issue_date', 'status', 'document_type', 'pos_id']);

        foreach ($invoices as $inv) {
            $m = $monthOf($inv->issue_date);
            $y = $yearOf($inv->issue_date);
            if (! $m || ! $y) {
                continue;
            }
            $ex = 0.0;
            $iva = 0.0;
            foreach ($inv->items as $line) {
                $lineEx = (float) $line->price * (float) $line->quantity - (float) ($line->discount ?? 0);
                $ex += $lineEx;
                $rate = 0.0;
                foreach (explode(',', (string) ($line->tax ?? '')) as $taxId) {
                    $taxId = trim($taxId);
                    if ($taxId !== '' && isset($rates[$taxId])) {
                        $rate += (float) $rates[$taxId];
                    }
                }
                $iva += $lineEx * $rate / 100;
            }
            $ex = round($ex, 2);
            $iva = round($iva, 2);

            if ((int) $inv->status === Invoice::STATUS_DRAFT) {
                if ($y === $year) {
                    $excluded['draftInvoices'][$m]['count']++;
                    $excluded['draftInvoices'][$m]['ex'] += $ex;
                }
                continue;
            }
            if (! in_array((int) $inv->status, [Invoice::STATUS_SENT, Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_PAID], true)) {
                continue;
            }
            if (($inv->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                continue;
            }

            if ($y !== $year && $y !== $year - 1) {
                continue;
            }
            if ($y === $year) {
                $revenue[$m]['ex'] += $ex;
                $revenue[$m]['iva'] += $iva;
                $revenue[$m]['total'] += $ex + $iva;
                // Sales channel of this invoice (POS outlet, or Outras
                // for direct invoices / retail POS).
                $channel = $inv->pos_id ? ($posChannel[(int) $inv->pos_id] ?? 'Outras') : 'Outras';
                $channels[$channel][$m]['ex'] += $ex;
                $channels[$channel][$m]['iva'] += $iva;
            } else {
                $priorRevenue[$m]['ex'] += $ex;
                $priorRevenue[$m]['iva'] += $iva;
                $priorRevenue[$m]['total'] += $ex + $iva;
            }
        }
        foreach ([$revenue, $priorRevenue] as &$set) {
            foreach ($set as $m => &$month) {
                foreach (['ex', 'iva', 'total'] as $k) {
                    $month[$k] = round($month[$k], 2);
                }
            }
            unset($month);
        }
        unset($set);

        // --- POS orders left out of revenue (no linked invoice) --------
        // A completed POS sale creates its invoice; a Pos row with no
        // invoice is still an open (or cancelled) order, not a sale.
        $openPos = Pos::where('created_by', $creatorId)
            ->whereYear('pos_date', $year)
            ->whereDoesntHave('invoice')
            ->with(['items', 'posPayments'])
            ->get();
        foreach ($openPos as $pos) {
            $m = $monthOf($pos->pos_date);
            if (! $m) {
                continue;
            }
            $excluded['openOrders'][$m]['count']++;
            $excluded['openOrders'][$m]['total'] += (float) $pos->getTotal();
        }

        // --- Costs: Management Documents (Documentos) -------------------
        // The client's real expense documents, aggregated by category
        // (Rules) and cost centre — the file's SUMIFS. This REPLACES
        // any system-derived costs: counting both would double-count
        // the same cost. Sales stay automatic.
        $docCosts = ManagementDocumentCosts::forYear($creatorId, $year);
        $cogs = $docCosts['cogs'];
        $variables = $docCosts['variables'];
        $delivery = $docCosts['delivery'];
        $other = $docCosts['other'];
        $expenses = $docCosts['fixed'];
        $deptNames = [];
        $departmentCentres = $docCosts['personnel']['department_centres'] ?? [];
        foreach ($docCosts['personnel']['departments'] as $label => $months) {
            $deptNames[$label] = true;
            foreach ($months as $m => $amount) {
                $personnel[$m]['departments'][$label] = round($amount, 2);
            }
        }
        for ($m = 1; $m <= 12; $m++) {
            $personnel[$m]['inss'] = round($docCosts['personnel']['inss'][$m], 2);
            $personnel[$m]['total'] = round($docCosts['personnel']['total'][$m], 2);
            ksort($personnel[$m]['departments']);
        }
        $departments = array_keys($deptNames);
        sort($departments);

        // Costs above are already rounded per month by the aggregation.
        for ($m = 1; $m <= 12; $m++) {
            $cogs[$m] = round($cogs[$m], 2);
            $variables[$m] = round($variables[$m], 2);
            $delivery[$m] = round($delivery[$m], 2);
            $other[$m] = round($other[$m], 2);
            $expenses[$m] = round($expenses[$m], 2);
            $excluded['draftInvoices'][$m]['ex'] = round($excluded['draftInvoices'][$m]['ex'], 2);
            $excluded['openOrders'][$m]['total'] = round($excluded['openOrders'][$m]['total'], 2);
        }

        // --- Depreciation: Fixed Assets (posted entries only) --------
        // AMORTIZAÇÕES have no system source (the FA module only does
        // depreciation) and stay 0 unless history supplies them.
        // FA depreciation journals are system journals, so they can
        // never double-count with the journal cost source above.
        $depreciation = self::postedDepreciation($creatorId, $year);

        // --- Historical data overlay (imported Excel figures) -----------
        // One row per (year, month, line). A row wins for its line-month:
        // the imported month is the source of truth, so system documents
        // in that month never add on top of it (no double-counting).
        $historyMonths = [];
        $historyLines = [];
        if ($applyHistory) {
            $hist = [];
            foreach (ManagementReportHistory::where('created_by', $creatorId)
                ->whereIn('year', [$year, $year - 1])
                ->get(['year', 'month', 'line', 'amount']) as $row) {
                $hist[(int) $row->year][(int) $row->month][$row->line] = (float) $row->amount;
            }
            $at = static fn (int $y, int $m, string $line): ?float => $hist[$y][$m][$line] ?? null;

            for ($m = 1; $m <= 12; $m++) {
                $monthHist = $hist[$year][$m] ?? [];
                if ($monthHist !== []) {
                    $historyMonths[] = $m;
                }
                // Plain lines: history wins, else the system value (0 for
                // lines with no system source at all).
                $v = $at($year, $m, 'cogs');
                if ($v !== null) {
                    $cogs[$m] = $v;
                }
                $v = $at($year, $m, 'variables');
                if ($v !== null) {
                    $variables[$m] = $v;
                }
                $v = $at($year, $m, 'fixed');
                if ($v !== null) {
                    $expenses[$m] = $v;
                }
                $v = $at($year, $m, 'other');
                if ($v !== null) {
                    $other[$m] = $v;
                }
                $v = $at($year, $m, 'depreciation');
                if ($v !== null) {
                    $depreciation[$m] = $v;
                }
                $v = $at($year, $m, 'amortization');
                if ($v !== null) {
                    $amortization[$m] = $v;
                }

                // Personnel: any personnel:* row makes the whole month
                // history-sourced — splits sum to the total, the
                // 'Impostos e Outros Custos' split is the INSS sub-row.
                $persHist = [];
                foreach ($monthHist as $line => $amount) {
                    if (str_starts_with($line, 'personnel:')) {
                        $persHist[substr($line, strlen('personnel:'))] = $amount;
                    }
                }
                if ($persHist !== []) {
                    $personnel[$m]['departments'] = [];
                    $personnel[$m]['inss'] = 0.0;
                    $personnel[$m]['total'] = 0.0;
                    foreach ($persHist as $name => $amount) {
                        if ($name === ManagementReportHistory::INSS_NAME) {
                            $personnel[$m]['inss'] = round($amount, 2);
                        } else {
                            $personnel[$m]['departments'][$name] = round($amount, 2);
                        }
                        $personnel[$m]['total'] += $amount;
                    }
                    $personnel[$m]['total'] = round($personnel[$m]['total'], 2);
                    ksort($personnel[$m]['departments']);
                }

                // Revenue: direct history keys win.
                $revEx = $at($year, $m, 'revenue_ex');
                $revIva = $at($year, $m, 'revenue_iva');
                if ($revEx !== null) {
                    $revenue[$m]['ex'] = round($revEx, 2);
                }
                if ($revIva !== null) {
                    $revenue[$m]['iva'] = round($revIva, 2);
                }
                if ($revEx !== null || $revIva !== null) {
                    $revenue[$m]['total'] = round($revenue[$m]['ex'] + $revenue[$m]['iva'], 2);
                }

                // Prior-year revenue: history for year-1 wins per month.
                $pEx = $at($year - 1, $m, 'revenue_ex');
                if ($pEx !== null) {
                    $priorRevenue[$m]['ex'] = round($pEx, 2);
                }
                $pIva = $at($year - 1, $m, 'revenue_iva');
                if ($pIva !== null) {
                    $priorRevenue[$m]['iva'] = round($pIva, 2);
                }
                if ($pEx !== null || $pIva !== null) {
                    $priorRevenue[$m]['total'] = round($priorRevenue[$m]['ex'] + $priorRevenue[$m]['iva'], 2);
                }

                // Sales channels: a channel history row wins for its
                // channel-month (Software Faturação is the total — it
                // follows revenue_ex / revenue_iva above).
                foreach (ManagementReportHistory::SHEET_CHANNELS as $ch) {
                    if ($ch === 'Software Faturação') {
                        continue;
                    }
                    $chEx = $at($year, $m, "channel:{$ch}:ex");
                    if ($chEx !== null) {
                        $channels[$ch][$m]['ex'] = round($chEx, 2);
                    }
                    $chIva = $at($year, $m, "channel:{$ch}:iva");
                    if ($chIva !== null) {
                        $channels[$ch][$m]['iva'] = round($chIva, 2);
                    }
                }
            }

            $historyLines = $hist[$year] ?? [];

            // Departments = system names + imported names (minus the INSS
            // sub-row, which is rendered separately). Imported names
            // have no cost centre, so they drill down by month only.
            $deptSet = [];
            foreach ($departments as $d) {
                $deptSet[$d] = true;
            }
            foreach ($hist[$year] ?? [] as $monthHist) {
                foreach ($monthHist as $line => $amount) {
                    if (str_starts_with($line, 'personnel:')) {
                        $name = substr($line, strlen('personnel:'));
                        if ($name !== ManagementReportHistory::INSS_NAME) {
                            $deptSet[$name] = true;
                            $departmentCentres[$name] ??= null;
                        }
                    }
                }
            }
            $departments = array_keys($deptSet);
            sort($departments);
        }

        // Outras is the residual, so the channels always sum to the
        // total (direct invoices + retail POS, or 0 in a history
        // month whose split is fully imported). Software Faturação
        // is the total itself — the file's shape.
        for ($m = 1; $m <= 12; $m++) {
            $channels['Outras'][$m]['ex'] = round(
                $revenue[$m]['ex'] - $channels['Restaurante'][$m]['ex'] - $channels['Bar'][$m]['ex'] - $channels['Eventos'][$m]['ex'],
                2
            );
            $channels['Outras'][$m]['iva'] = round(
                $revenue[$m]['iva'] - $channels['Restaurante'][$m]['iva'] - $channels['Bar'][$m]['iva'] - $channels['Eventos'][$m]['iva'],
                2
            );
            foreach (['Restaurante', 'Bar', 'Eventos', 'Outras'] as $ch) {
                $channels[$ch][$m]['ex'] = round($channels[$ch][$m]['ex'], 2);
                $channels[$ch][$m]['iva'] = round($channels[$ch][$m]['iva'], 2);
                $channels[$ch][$m]['total'] = round($channels[$ch][$m]['ex'] + $channels[$ch][$m]['iva'], 2);
            }
            $channels['Software Faturação'][$m] = [
                'ex' => round($revenue[$m]['ex'], 2),
                'iva' => round($revenue[$m]['iva'], 2),
                'total' => round($revenue[$m]['total'], 2),
            ];
        }

        return [
            'year' => $year,
            'revenue' => $revenue,
            'priorRevenue' => $priorRevenue,
            'channels' => $channels,
            'cogs' => $cogs,
            'personnel' => $personnel,
            'departments' => $departments,
            // Department label => cost centre id (null: no centre, a
            // journal account, or an imported history name) — the P&L
            // drill-down links department rows to the Documents list.
            'departmentCentres' => $departmentCentres,
            'expenses' => $expenses,
            'excluded' => $excluded,
            'variables' => $variables,
            'delivery' => $delivery,
            'other' => $other,
            'depreciation' => $depreciation,
            'amortization' => $amortization,
            'historyMonths' => $historyMonths,
            // Per-line history map: [month => [line => amount]] — the
            // views mark each history-sourced cell with * (not the
            // whole month).
            'historyLines' => $historyLines,
        ];
    }

    /**
     * Monthly posted depreciation from the Fixed Assets module
     * (posted entries only — a calculated-but-unposted run is not a
     * cost yet). Returns [1..12] amounts. The table may not exist
     * on installs without the FA module: checked fresh on every call
     * (never memoized — see the Schema::hasTable lesson).
     */
    private static function postedDepreciation(int $creatorId, int $year): array
    {
        $months = array_fill(1, 12, 0.0);
        if (! Schema::hasTable('fixed_asset_depreciation_entries')) {
            return $months;
        }
        $rows = FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->whereYear('period', $year)
            ->get(['period', 'posted_amount']);
        foreach ($rows as $row) {
            $m = (int) date('m', strtotime((string) $row->period));
            if ($m >= 1 && $m <= 12) {
                $months[$m] = round($months[$m] + (float) $row->posted_amount, 2);
            }
        }

        return $months;
    }

    /**
     * Which Sales channel one POS order belongs to: 'Bar' when the
     * order sits on a table in an area named Bar (case-insensitive),
     * 'Restaurante' for any other restaurant-branch order, 'Outras'
     * for retail branches and orders without a branch.
     */
    private static function posChannelName(Pos $pos): string
    {
        $branch = $pos->branch;
        if (! $branch || $branch->pos_type !== Branch::POS_TYPE_RESTAURANT) {
            return 'Outras';
        }
        $areaName = $pos->table?->area?->name ?? '';
        if ($areaName !== '' && mb_stripos($areaName, 'bar') !== false) {
            return 'Bar';
        }

        return 'Restaurante';
    }

    /**
     * A built dataset as line-keyed monthly values (the history page's
     * shape): [month => ['revenue_ex' => ..., 'personnel:{name}' => ...]].
     * Used to show the automatic system values next to the imported ones.
     */
    public static function toLines(array $data): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $lines = [
                'revenue_ex' => $data['revenue'][$m]['ex'],
                'revenue_iva' => $data['revenue'][$m]['iva'],
                'cogs' => $data['cogs'][$m],
                'variables' => $data['variables'][$m],
                'fixed' => $data['expenses'][$m],
                'other' => $data['other'][$m],
                'depreciation' => $data['depreciation'][$m],
                'amortization' => $data['amortization'][$m],
            ];
            foreach ($data['personnel'][$m]['departments'] as $name => $amount) {
                $lines['personnel:' . $name] = $amount;
            }
            $lines['personnel:' . ManagementReportHistory::INSS_NAME] = $data['personnel'][$m]['inss'];
            foreach (ManagementReportHistory::SHEET_CHANNELS as $ch) {
                $lines["channel:{$ch}:ex"] = $data['channels'][$ch][$m]['ex'] ?? 0.0;
                $lines["channel:{$ch}:iva"] = $data['channels'][$ch][$m]['iva'] ?? 0.0;
            }
            $out[$m] = $lines;
        }

        return $out;
    }
}
