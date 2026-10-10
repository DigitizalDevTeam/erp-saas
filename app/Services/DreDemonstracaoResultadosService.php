<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Demonstração dos Resultados" — comparative P&L from chart account codes and transaction_lines.
 * Mapping follows PCGAP / SNC-style 6xx (costs) and 7xx (revenues); adjust account codes in your CoA to match.
 */
class DreDemonstracaoResultadosService
{
    /** Section headings in client-required Portuguese format. */
    private const HEADING_PT = [
        '_h1' => 'Custos e perdas',
        '_h2' => 'Custo dos meios circulantes materiais vendidos ou consumidos',
        '_h3' => 'Custos com o pessoal',
        '_h4' => 'Fornecimentos e serviços de terceiros',
        '_r1' => 'Proveitos e ganhos',
        '_r2' => 'Vendas:',
    ];

    /** Sub-totals / totals / special rows in client-required Portuguese format. */
    private const STATIC_LABEL_PT = [
        'sub_mat' => 'Sub-total..............',
        'sub_pers' => 'Sub-total..............',
        'sub_op_cost' => 'Sub-total.............',
        'A' => 'Total dos custos operacionais            (A).....................................',
        'fin_cost' => 'Custos e perdas financeiros               (C).....................................',
        'ext_cost' => 'Custos e perdas extraordinários         (E).....................................',
        'G' => 'Total dos custos                                  (G).....................................',
        'net_line_cost' => 'Resultado líquido do exercício.',
        'B' => 'Total dos proveitos operacionais      (B)',
        'fin_rev' => 'Proveitos e ganhos financeiros         (D)',
        'ext_rev' => 'Proveitos ou ganhos extraordinários (F)',
        'H' => 'Total dos proveitos                         (H)',
    ];

    /** Fallback when no chart account matches. */
    private const LINE_FALLBACK_PT = [
        'c612' => '612 - De mercadorias...................................................................',
        'c616' => '616 - De matérias primas, auxiliares e materiais',
        'c61_other' => '611/613/614/615 - Outros custos de meios circulantes',
        'c621' => '621 - Remunerações dos órgãos sociais.....................................',
        'c622' => '622 - Remunerações dos trabalhadores......................................',
        'c623' => '623 - Encargos sobre remunerações...........................................',
        'c624' => '624 - Pensões...............................................................................',
        'c625' => '625 - Ajudas de custo..................................................................',
        'c626' => '626 - Indemnizações.....................................................................',
        'c627' => '627 - Seg. de acidentes no trabalho e doenças profissionais....',
        'c628' => '628 - Custos de acção Social.......................................................',
        'c629' => '629 - Outros custos com pessoal................................................',
        'c631' => '631 - Subcontratos.......................................................................',
        'c632' => '632 - Fornecimentos e Serviços..................................................',
        'c635_taxes' => 'Impostos e taxas...........................................................................',
        'c_amort' => 'Amortizações do exercício de imobil. corpórias e incorpórias.....',
        'c_provision' => 'Provisões do exercício..................................................................',
        'c_other_ops' => 'Outros custos e perdas operacionais...........................................',
        'c_unclassified' => 'Outros custos operacionais',
        'rev_711' => '711 - Mercadorias',
        'rev_712' => '712 - Produtos acabados e intermédios',
        'rev_713_718' => 'Vendas de serviços',
        'rev_services' => 'Prestação de serviços',
        'rev_production_var' => 'Variação da produção',
        'rev_investment' => 'Investimentos realizados pela empresa',
        'rev_subsidies' => 'Subsídios à exploração',
        'rev_supplementary' => 'Proveitos suplementares',
        'rev_other_ops' => 'Outros proveitos e ganhos operacionais',
        'rev_unclassified' => 'Outros proveitos e ganhos operacionais',
        'income_tax' => 'Impostos sobre rendimento',
    ];

    /** @var list<string> */
    private const A_LINE_IDS = [
        'c612', 'c616', 'c61_other',
        'c621', 'c622', 'c623', 'c624', 'c625', 'c626', 'c627', 'c628', 'c629',
        'c631', 'c632', 'c635_taxes', 'c_amort', 'c_provision', 'c_other_ops', 'c_unclassified',
    ];

    /** @var list<string> */
    private const B_LINE_IDS = [
        'rev_711', 'rev_712', 'rev_713_718', 'rev_services', 'rev_production_var',
        'rev_investment', 'rev_subsidies', 'rev_supplementary', 'rev_other_ops', 'rev_unclassified',
    ];

    /**
     * @return array{
     *     year1: int,
     *     year2: int,
     *     amounts: array<string, array{y1: float, y2: float}>,
     *     A: array{y1: float, y2: float},
     *     B: array{y1: float, y2: float},
     *     C: array{y1: float, y2: float},
     *     D: array{y1: float, y2: float},
     *     E: array{y1: float, y2: float},
     *     F: array{y1: float, y2: float},
     *     G: array{y1: float, y2: float},
     *     H: array{y1: float, y2: float},
     *     operating_result: array{y1: float, y2: float},
     *     financial_result: array{y1: float, y2: float},
     *     extraordinary_result: array{y1: float, y2: float},
     *     net_result: array{y1: float, y2: float},
     *     cost_display: list<array{key: string, label: string, sub?: bool, total?: bool}>,
     *     revenue_display: list<array{key: string, label: string, sub?: bool, total?: bool}>
     * }
     */
    public static function build(int $creatorId, int $year1, int $year2): array
    {
        if ($year2 > $year1) {
            [$year1, $year2] = [$year2, $year1];
        }

        $amounts = self::aggregateByLineAndYear($creatorId, $year1, $year2);

        $sumIds = function (array $ids, int $yKey) use ($amounts): float {
            $t = 0.0;
            foreach ($ids as $id) {
                $k = $yKey === 1 ? 'y1' : 'y2';
                $t += $amounts[$id][$k] ?? 0.0;
            }

            return round($t, 2);
        };

        $pair = function (array $ids) use ($sumIds): array {
            return ['y1' => $sumIds($ids, 1), 'y2' => $sumIds($ids, 2)];
        };

        $A = $pair(self::A_LINE_IDS);
        $B = $pair(self::B_LINE_IDS);
        $C = $pair(['fin_cost']);
        $D = $pair(['fin_rev']);
        $E = $pair(['ext_cost']);
        $F = $pair(['ext_rev']);
        $tax = $pair(['income_tax']);

        $G = [
            'y1' => round($A['y1'] + $C['y1'] + $E['y1'] + $tax['y1'], 2),
            'y2' => round($A['y2'] + $C['y2'] + $E['y2'] + $tax['y2'], 2),
        ];
        $H = [
            'y1' => round($B['y1'] + $D['y1'] + $F['y1'], 2),
            'y2' => round($B['y2'] + $D['y2'] + $F['y2'], 2),
        ];

        $operating = [
            'y1' => round($B['y1'] - $A['y1'], 2),
            'y2' => round($B['y2'] - $A['y2'], 2),
        ];
        $financial = [
            'y1' => round($D['y1'] - $C['y1'], 2),
            'y2' => round($D['y2'] - $C['y2'], 2),
        ];
        $extraordinary = [
            'y1' => round($F['y1'] - $E['y1'], 2),
            'y2' => round($F['y2'] - $E['y2'], 2),
        ];
        $net = [
            'y1' => round($H['y1'] - $G['y1'], 2),
            'y2' => round($H['y2'] - $G['y2'], 2),
        ];

        return [
            'year1' => $year1,
            'year2' => $year2,
            'amounts' => $amounts,
            'A' => $A,
            'B' => $B,
            'C' => $C,
            'D' => $D,
            'E' => $E,
            'F' => $F,
            'G' => $G,
            'H' => $H,
            'income_tax' => $tax,
            'operating_result' => $operating,
            'financial_result' => $financial,
            'extraordinary_result' => $extraordinary,
            'net_result' => $net,
            'cost_display' => self::costDisplaySchema(),
            'revenue_display' => self::revenueDisplaySchema(),
        ];
    }

    /**
     * @return array<string, array{y1: float, y2: float}>
     */
    private static function aggregateByLineAndYear(int $creatorId, int $year1, int $year2): array
    {
        $years = [$year1, $year2];
        $minDate = min($year1, $year2).'-01-01';
        $maxDate = max($year1, $year2).'-12-31';

        $closingJournalIds = DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-%-CLOSE%')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $rows = DB::table('transaction_lines as tl')
            ->join('chart_of_accounts as c', 'c.id', '=', 'tl.account_id')
            ->where('tl.created_by', $creatorId)
            ->whereBetween('tl.date', [$minDate, $maxDate])
            // Same basis as the Profit & Loss statement (the reference report):
            // year-end closing journals (FY-*-CLOSE*) only move class 6/7
            // balances into result accounts — counting them here cancels the
            // year's revenue and costs to zero after closing.
            ->where(function ($w) use ($closingJournalIds) {
                $w->whereNull('tl.reference')
                    ->orWhere('tl.reference', '!=', 'Journal')
                    ->orWhereNotIn('tl.reference_id', $closingJournalIds);
            })
            ->groupBy(DB::raw('YEAR(tl.date)'), 'c.id', 'c.code', 'c.name', 'c.name_pt_br')
            ->select(
                DB::raw('YEAR(tl.date) as fy'),
                'c.code',
                'c.name',
                'c.name_pt_br',
                DB::raw('SUM(tl.debit) as debit'),
                DB::raw('SUM(tl.credit) as credit')
            )
            ->get();

        $allIds = array_merge(
            self::A_LINE_IDS,
            self::B_LINE_IDS,
            ['fin_cost', 'fin_rev', 'ext_cost', 'ext_rev', 'income_tax', 'unmapped']
        );
        $amounts = [];
        foreach ($allIds as $id) {
            $amounts[$id] = ['y1' => 0.0, 'y2' => 0.0];
        }

        foreach ($rows as $row) {
            $fy = (int) $row->fy;
            if (! in_array($fy, $years, true)) {
                continue;
            }
            $yKey = $fy === $year1 ? 'y1' : 'y2';
            $lineId = self::classifyLineIdFromAccount(
                (string) $row->code,
                (string) ($row->name_pt_br ?? ''),
                (string) ($row->name ?? '')
            );
            if ($lineId === 'unmapped') {
                $digits = preg_replace('/\D/', '', (string) $row->code) ?? '';
                if ($digits !== '' && ctype_digit($digits)) {
                    $digits = ltrim($digits, '0') ?: '0';
                    if ($digits[0] === '6') {
                        $lineId = 'c_unclassified';
                    } elseif ($digits[0] === '7') {
                        $lineId = 'rev_unclassified';
                    }
                }
            }
            if (! isset($amounts[$lineId])) {
                $lineId = 'unmapped';
            }
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;
            $amt = self::signedMovement($lineId, $debit, $credit);
            $amounts[$lineId][$yKey] = round(($amounts[$lineId][$yKey] ?? 0) + $amt, 2);
        }

        foreach ($amounts as $k => $v) {
            $amounts[$k]['y1'] = round($v['y1'], 2);
            $amounts[$k]['y2'] = round($v['y2'], 2);
        }

        return $amounts;
    }

    private static function signedMovement(string $lineId, float $debit, float $credit): float
    {
        if (str_starts_with($lineId, 'rev_') || $lineId === 'fin_rev' || $lineId === 'ext_rev') {
            return round($credit - $debit, 2);
        }

        return round($debit - $credit, 2);
    }

    /**
     * Map chart account code (e.g. "612", "612.01", "6211") to internal DRE line id.
     */
    public static function classifyLineId(string $code): string
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if ($digits === '' || ! ctype_digit($digits)) {
            return 'unmapped';
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 'unmapped';
        }

        // Income tax (part of G, not A)
        if (str_starts_with($digits, '681')) {
            return 'income_tax';
        }

        // Financial costs (C)
        if (str_starts_with($digits, '67')) {
            return 'fin_cost';
        }

        // Financial income (D) — 76x except operating slice; narrow 761,762,763–769,791
        if (str_starts_with($digits, '761') || str_starts_with($digits, '762')) {
            return 'fin_rev';
        }
        if (preg_match('/^76[3-9]/', $digits)) {
            return 'fin_rev';
        }
        if (str_starts_with($digits, '791')) {
            return 'fin_rev';
        }

        // Extraordinary
        if (str_starts_with($digits, '687') || str_starts_with($digits, '698')) {
            return 'ext_cost';
        }
        if (str_starts_with($digits, '788') || str_starts_with($digits, '798')) {
            return 'ext_rev';
        }

        // Revenues class 7 (operating — B)
        if ($digits[0] === '7') {
            if (str_starts_with($digits, '711')) {
                return 'rev_711';
            }
            if (str_starts_with($digits, '712')) {
                return 'rev_712';
            }
            if (preg_match('/^71[3-8]/', $digits)) {
                return 'rev_713_718';
            }
            if (preg_match('/^72/', $digits)) {
                return 'rev_services';
            }
            if (preg_match('/^73/', $digits)) {
                return 'rev_production_var';
            }
            if (preg_match('/^758/', $digits)) {
                return 'rev_investment';
            }
            if (preg_match('/^74/', $digits)) {
                return 'rev_subsidies';
            }
            if (preg_match('/^75/', $digits)) {
                return 'rev_supplementary';
            }
            if (preg_match('/^76/', $digits)) {
                // 761+ handled above as financial; remaining 760,762 handled — 760?
                return 'rev_other_ops';
            }
            if (preg_match('/^77/', $digits) || preg_match('/^78/', $digits)) {
                return 'rev_unclassified';
            }

            return 'rev_unclassified';
        }

        // Costs class 6 (operating — A), excluding 67 (financial) and 681 (tax)
        if ($digits[0] === '6') {
            if (str_starts_with($digits, '612')) {
                return 'c612';
            }
            if (str_starts_with($digits, '616')) {
                return 'c616';
            }
            if (str_starts_with($digits, '61')) {
                return 'c61_other';
            }
            for ($i = 629; $i >= 621; $i--) {
                if (str_starts_with($digits, (string) $i)) {
                    return 'c'.$i;
                }
            }
            if (str_starts_with($digits, '620')) {
                return 'c622';
            }
            if (str_starts_with($digits, '631')) {
                return 'c631';
            }
            if (str_starts_with($digits, '632') || str_starts_with($digits, '633') || str_starts_with($digits, '634')) {
                return 'c632';
            }
            if (str_starts_with($digits, '635') || str_starts_with($digits, '636')) {
                return 'c635_taxes';
            }
            if (preg_match('/^64/', $digits)) {
                return 'c_amort';
            }
            if (preg_match('/^65/', $digits)) {
                return 'c_provision';
            }
            if (preg_match('/^69/', $digits)) {
                return 'c_other_ops';
            }
            if (preg_match('/^66/', $digits)) {
                return 'c_other_ops';
            }
            if (preg_match('/^68/', $digits)) {
                return 'c_other_ops';
            }

            return 'c_unclassified';
        }

        return 'unmapped';
    }

    public static function classifyLineIdFromAccount(string $code, ?string $namePt = null, ?string $nameEn = null): string
    {
        $byCode = self::classifyLineId($code);
        if ($byCode !== 'unmapped') {
            return $byCode;
        }

        // The name fallback exists for legacy accounts without PCGAP codes.
        // Never apply it to balance-sheet / result accounts (classes 1–5, 8):
        // e.g. "Fornecedores" (suppliers, 42) contains "fornec…" and would
        // otherwise be booked as a class 632 cost, distorting the statement.
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if ($digits !== '' && ! in_array($digits[0], ['6', '7'], true)) {
            return 'unmapped';
        }

        $name = trim((string) ($namePt ?: $nameEn));
        if ($name === '') {
            return 'unmapped';
        }

        $n = mb_strtolower($name, 'UTF-8');
        $contains = static fn (string $needle): bool => str_contains($n, $needle);

        if ($contains('mercador')) {
            return 'c612';
        }
        if ($contains('matéria') || $contains('materia prima') || $contains('consum')) {
            return 'c616';
        }
        if ($contains('órgão social') || $contains('orgao social')) {
            return 'c621';
        }
        if ($contains('trabalhador') || $contains('remunera')) {
            return 'c622';
        }
        if ($contains('encargo')) {
            return 'c623';
        }
        if ($contains('pens')) {
            return 'c624';
        }
        if ($contains('ajuda de custo')) {
            return 'c625';
        }
        if ($contains('indemniza') || $contains('indeniza')) {
            return 'c626';
        }
        if ($contains('acidente') || $contains('doença') || $contains('doenca')) {
            return 'c627';
        }
        if ($contains('acção social') || $contains('acao social')) {
            return 'c628';
        }
        if ($contains('pessoal')) {
            return 'c629';
        }
        if ($contains('subcontrat')) {
            return 'c631';
        }
        if ($contains('fornec') || $contains('servi')) {
            return 'c632';
        }
        if ($contains('imposto') || $contains('taxa')) {
            return 'c635_taxes';
        }
        if ($contains('amortiza') || $contains('depreci')) {
            return 'c_amort';
        }
        if ($contains('provis')) {
            return 'c_provision';
        }
        if ($contains('financeir')) {
            return $contains('custo') || $contains('perda') ? 'fin_cost' : 'fin_rev';
        }
        if ($contains('extraordin')) {
            return $contains('custo') || $contains('perda') ? 'ext_cost' : 'ext_rev';
        }
        if ($contains('mercador') && ($contains('venda') || $contains('proveito'))) {
            return 'rev_711';
        }
        if ($contains('produto acabado') || $contains('intermédio') || $contains('intermedio')) {
            return 'rev_712';
        }
        if ($contains('venda de servi')) {
            return 'rev_713_718';
        }
        if ($contains('variação da produção') || $contains('variacao da producao')) {
            return 'rev_production_var';
        }
        if ($contains('investimento')) {
            return 'rev_investment';
        }
        if ($contains('subsídio') || $contains('subsidio')) {
            return 'rev_subsidies';
        }
        if ($contains('suplementar')) {
            return 'rev_supplementary';
        }
        if ($contains('proveito') || $contains('ganho')) {
            return 'rev_other_ops';
        }

        return 'unmapped';
    }

    /**
     * @return list<array{key: string, sub?: bool, total?: bool}>
     */
    private static function costDisplaySchema(): array
    {
        return [
            ['key' => '_h1'],
            ['key' => '_h2'],
            ['key' => 'c612'],
            ['key' => 'c616'],
            ['key' => 'c61_other'],
            ['key' => 'sub_mat', 'sub' => true],
            ['key' => '_h3'],
            ['key' => 'c621'],
            ['key' => 'c622'],
            ['key' => 'c623'],
            ['key' => 'c624'],
            ['key' => 'c625'],
            ['key' => 'c626'],
            ['key' => 'c627'],
            ['key' => 'c628'],
            ['key' => 'c629'],
            ['key' => 'sub_pers', 'sub' => true],
            ['key' => '_h4'],
            ['key' => 'c631'],
            ['key' => 'c632'],
            ['key' => 'c635_taxes'],
            ['key' => 'c_amort'],
            ['key' => 'c_provision'],
            ['key' => 'c_other_ops'],
            ['key' => 'c_unclassified'],
            ['key' => 'sub_op_cost', 'sub' => true],
            ['key' => 'A', 'total' => true],
            ['key' => 'fin_cost', 'total' => true],
            ['key' => 'ext_cost', 'total' => true],
            ['key' => 'income_tax'],
            ['key' => 'G', 'total' => true],
            ['key' => 'net_line_cost', 'total' => true],
        ];
    }

    /**
     * @return list<array{key: string, sub?: bool, total?: bool}>
     */
    private static function revenueDisplaySchema(): array
    {
        return [
            ['key' => '_r1'],
            ['key' => '_r2'],
            ['key' => 'rev_711'],
            ['key' => 'rev_712'],
            ['key' => 'rev_713_718'],
            ['key' => 'rev_services'],
            ['key' => 'rev_production_var'],
            ['key' => 'rev_investment'],
            ['key' => 'rev_subsidies'],
            ['key' => 'rev_supplementary'],
            ['key' => 'rev_other_ops'],
            ['key' => 'rev_unclassified'],
            ['key' => 'B', 'total' => true],
            ['key' => 'fin_rev', 'total' => true],
            ['key' => 'ext_rev', 'total' => true],
            ['key' => 'H', 'total' => true],
        ];
    }

    public static function resolveRowLabel(string $key, int $creatorId, ?string $lang = null): string
    {
        if (isset(self::HEADING_PT[$key])) {
            return self::HEADING_PT[$key];
        }
        if (isset(self::STATIC_LABEL_PT[$key])) {
            return self::STATIC_LABEL_PT[$key];
        }

        return self::LINE_FALLBACK_PT[$key] ?? $key;
    }

    private static function summaryRows(array $report): array
    {
        return [
            [
                'label' => 'Resultados operacionais:         (B - A)',
                'y1' => $report['operating_result']['y1'],
                'y2' => $report['operating_result']['y2'],
            ],
            [
                'label' => 'Resultados financeiros:           (D - C)',
                'y1' => $report['financial_result']['y1'],
                'y2' => $report['financial_result']['y2'],
            ],
            [
                'label' => 'Resultados extraordinários:     (F - E)',
                'y1' => $report['extraordinary_result']['y1'],
                'y2' => $report['extraordinary_result']['y2'],
            ],
            [
                'label' => 'Resultado líquido do exercício: (H - G)',
                'y1' => $report['net_result']['y1'],
                'y2' => $report['net_result']['y2'],
            ],
        ];
    }

    /**
     * Resolve computed row values for cost section (sub-totals, A, G, etc.).
     *
     * @param  array<string, array{y1: float, y2: float}>  $amounts
     * @return array<string, array{y1: float, y2: float}>
     */
    public static function resolveCostRowAmounts(array $amounts, array $A, array $G, array $net): array
    {
        $g = static function (string $key, string $yk) use ($amounts): float {
            return $amounts[$key][$yk] ?? 0.0;
        };

        $matKeys = ['c612', 'c616', 'c61_other'];
        $persKeys = ['c621', 'c622', 'c623', 'c624', 'c625', 'c626', 'c627', 'c628', 'c629'];
        $opKeys = array_merge($matKeys, $persKeys, ['c631', 'c632', 'c635_taxes', 'c_amort', 'c_provision', 'c_other_ops', 'c_unclassified']);

        $sumKeys = static function (array $keys, string $yk) use ($g): float {
            $t = 0.0;
            foreach ($keys as $k) {
                $t += $g($k, $yk);
            }

            return round($t, 2);
        };

        $y = ['y1', 'y2'];
        $out = [];
        foreach ($y as $yk) {
            $out['sub_mat'][$yk] = $sumKeys($matKeys, $yk);
            $out['sub_pers'][$yk] = $sumKeys($persKeys, $yk);
            $out['sub_op_cost'][$yk] = $sumKeys($opKeys, $yk);
            $out['A'][$yk] = $A[$yk];
            $out['G'][$yk] = $G[$yk];
            $out['net_line_cost'][$yk] = $net[$yk];
        }

        return $out;
    }

    /**
     * Pre-computed rows for Blade (amounts per column).
     *
     * @param  array<string, mixed>  $report  output of build()
     * @return array{cost: list<array{label: string, y1: ?float, y2: ?float, style: string}>, revenue: list<array{label: string, y1: ?float, y2: ?float, style: string}>, summary: list<array{label: string, y1: float, y2: float}>}
     */
    public static function buildFilledRows(array $report, int $creatorId, ?string $lang = null): array
    {
        $amounts = $report['amounts'];
        $resolved = self::resolveCostRowAmounts(
            $amounts,
            $report['A'],
            $report['G'],
            $report['net_result']
        );

        $cell = static function (string $key, string $yk) use ($amounts): float {
            return (float) ($amounts[$key][$yk] ?? 0.0);
        };

        $costOut = [];
        foreach (self::costDisplaySchema() as $row) {
            $key = $row['key'];
            if (str_starts_with($key, '_h')) {
                $costOut[] = [
                    'label' => self::resolveRowLabel($key, $creatorId, $lang),
                    'y1' => null,
                    'y2' => null,
                    'style' => 'heading',
                ];
                continue;
            }
            $style = 'line';
            if (! empty($row['sub'])) {
                $style = 'subtotal';
            }
            if (! empty($row['total'])) {
                $style = 'total';
            }

            $y1 = 0.0;
            $y2 = 0.0;
            if ($key === 'sub_mat') {
                $y1 = $resolved['sub_mat']['y1'];
                $y2 = $resolved['sub_mat']['y2'];
            } elseif ($key === 'sub_pers') {
                $y1 = $resolved['sub_pers']['y1'];
                $y2 = $resolved['sub_pers']['y2'];
            } elseif ($key === 'sub_op_cost') {
                $y1 = $resolved['sub_op_cost']['y1'];
                $y2 = $resolved['sub_op_cost']['y2'];
            } elseif ($key === 'A') {
                $y1 = $report['A']['y1'];
                $y2 = $report['A']['y2'];
            } elseif ($key === 'G') {
                $y1 = $report['G']['y1'];
                $y2 = $report['G']['y2'];
            } elseif ($key === 'fin_cost') {
                $y1 = $cell('fin_cost', 'y1');
                $y2 = $cell('fin_cost', 'y2');
            } elseif ($key === 'ext_cost') {
                $y1 = $cell('ext_cost', 'y1');
                $y2 = $cell('ext_cost', 'y2');
            } elseif ($key === 'income_tax') {
                $y1 = $cell('income_tax', 'y1');
                $y2 = $cell('income_tax', 'y2');
            } elseif ($key === 'net_line_cost') {
                $y1 = $report['net_result']['y1'];
                $y2 = $report['net_result']['y2'];
            } else {
                $y1 = $cell($key, 'y1');
                $y2 = $cell($key, 'y2');
            }

            $costOut[] = [
                'label' => self::resolveRowLabel($key, $creatorId, $lang),
                'y1' => round($y1, 2),
                'y2' => round($y2, 2),
                'style' => $style,
            ];
        }

        $revOut = [];
        foreach (self::revenueDisplaySchema() as $row) {
            $key = $row['key'];
            if (str_starts_with($key, '_r')) {
                $revOut[] = [
                    'label' => self::resolveRowLabel($key, $creatorId, $lang),
                    'y1' => null,
                    'y2' => null,
                    'style' => 'heading',
                ];
                continue;
            }
            $style = 'line';
            if (! empty($row['sub'])) {
                $style = 'subtotal';
            }
            if (! empty($row['total'])) {
                $style = 'total';
            }

            $y1 = 0.0;
            $y2 = 0.0;
            if ($key === 'B') {
                $y1 = $report['B']['y1'];
                $y2 = $report['B']['y2'];
            } elseif ($key === 'H') {
                $y1 = $report['H']['y1'];
                $y2 = $report['H']['y2'];
            } elseif ($key === 'fin_rev') {
                $y1 = $cell('fin_rev', 'y1');
                $y2 = $cell('fin_rev', 'y2');
            } elseif ($key === 'ext_rev') {
                $y1 = $cell('ext_rev', 'y1');
                $y2 = $cell('ext_rev', 'y2');
            } else {
                $y1 = $cell($key, 'y1');
                $y2 = $cell($key, 'y2');
            }

            $revOut[] = [
                'label' => self::resolveRowLabel($key, $creatorId, $lang),
                'y1' => round($y1, 2),
                'y2' => round($y2, 2),
                'style' => $style,
            ];
        }

        return [
            'cost' => $costOut,
            'revenue' => $revOut,
            'summary' => self::summaryRows($report),
        ];
    }
}
