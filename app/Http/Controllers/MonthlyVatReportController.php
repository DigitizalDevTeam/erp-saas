<?php

namespace App\Http\Controllers;

use App\Models\Utility;
use App\Services\Accounting\VatModeloAReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlyVatReportController extends Controller
{
    public function __construct(
        private VatModeloAReportService $vatReport
    ) {}

    public function modeloA(Request $request)
    {
        if (! \Auth::user()->can('tax report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        [$year, $month, $start, $end] = $this->resolvePeriod($request);
        $insideDeadline = (int) $request->input('inside_deadline', 1) === 1;
        $settings = Utility::settings();
        $creatorId = \Auth::user()->creatorId();
        $company = $this->companyDefaults($settings);
        $manual = $this->manualInputs($request);
        $report = $this->buildReportData($creatorId, $start, $end, $manual, $insideDeadline);

        return view('reports.monthly.vat_modelo_a', [
            'period' => compact('year', 'month', 'start', 'end'),
            'insideDeadline' => $insideDeadline,
            'report' => $report,
            'company' => $company,
            'manualInputs' => $manual,
        ]);
    }

    public function exportModeloA(Request $request)
    {
        if (! \Auth::user()->can('tax report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        [$year, $month, $start, $end] = $this->resolvePeriod($request);
        $insideDeadline = (int) $request->input('inside_deadline', 1) === 1;
        $settings = Utility::settings();
        $creatorId = \Auth::user()->creatorId();
        $company = $this->companyDefaults($settings);
        $manual = $this->manualInputs($request);
        $report = $this->buildReportData($creatorId, $start, $end, $manual, $insideDeadline);

        $template = base_path('Modelo A.xlsx');
        if (! file_exists($template)) {
            return redirect()->back()->with('error', __('Template file Modelo A.xlsx not found in project root.'));
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getActiveSheet();

        $this->fillCell($sheet, 'AK7', sprintf('%02d / %d', $month, $year));
        $this->fillCell($sheet, 'AM9', $insideDeadline ? 'X' : '');
        $this->fillCell($sheet, 'AM11', $insideDeadline ? '' : 'X');

        $this->fillCell($sheet, 'T6', $company['nuit']);
        $this->fillCell($sheet, 'E16', $company['name']);
        $this->fillCell($sheet, 'H17', $company['address']);
        $this->fillCell($sheet, 'F18', $company['city']);
        $this->fillCell($sheet, 'AB18', $company['phone']);
        $this->fillCell($sheet, 'AJ18', $company['fax']);

        $this->fillCell($sheet, 'M31', $report['f01']);
        $this->fillCell($sheet, 'M33', $report['f03']);
        $this->fillCell($sheet, 'M35', $report['f04']);
        $this->fillCell($sheet, 'AJ31', $report['f02']);
        $this->fillCell($sheet, 'Y41', $report['f05']);
        $this->fillCell($sheet, 'Y43', $report['f06']);
        $this->fillCell($sheet, 'Y45', $report['f07']);
        $this->fillCell($sheet, 'Y47', $report['f08']);
        $this->fillCell($sheet, 'Y49', $report['f09']);
        $this->fillCell($sheet, 'AJ49', $report['f10']);
        $this->fillCell($sheet, 'Y52', $report['f12']);
        $this->fillCell($sheet, 'AJ52', $report['f13']);
        $this->fillCell($sheet, 'M52', $report['f11']);
        $this->fillCell($sheet, 'AJ55', $report['f14']);
        $this->fillCell($sheet, 'AJ58', $report['f15']);
        $this->fillCell($sheet, 'K63', $report['f16']);
        $this->fillCell($sheet, 'AJ63', $report['f17']);
        $this->fillCell($sheet, 'F69', $report['f18']);
        $this->fillCell($sheet, 'AJ73', $report['f20']);
        $this->fillCell($sheet, 'C83', $report['f21']);

        $fileName = sprintf('modelo_a_vat_%04d_%02d.xlsx', $year, $month);

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function fillCell(Worksheet $sheet, string $cell, float|string $value): void
    {
        $sheet->setCellValue($cell, $value);
    }

    private function resolvePeriod(Request $request): array
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return [$year, $month, $start, $end];
    }

    private function companyDefaults(array $settings): array
    {
        return [
            'name' => (string) ($settings['company_name'] ?? (\Auth::user()->name ?? config('app.name'))),
            'nuit' => (string) ($settings['vat_number'] ?? ''),
            'address' => (string) ($settings['company_address'] ?? ''),
            'city' => (string) ($settings['company_city'] ?? ''),
            'phone' => (string) ($settings['company_telephone'] ?? ''),
            'fax' => (string) ($settings['company_fax'] ?? ''),
        ];
    }

    private function manualInputs(Request $request): array
    {
        return [
            'manual_08' => $request->input('manual_08'),
            'manual_09' => $request->input('manual_09'),
            'manual_10' => $request->input('manual_10'),
            'manual_16' => $request->input('manual_16'),
            'manual_19' => $request->input('manual_19'),
            'manual_03' => $request->input('manual_03'),
            'manual_04' => $request->input('manual_04'),
        ];
    }

    private function amount(array $manual, string $key): float
    {
        $v = $manual[$key] ?? null;
        if ($v === null || $v === '') {
            return 0.0;
        }

        return round((float) str_replace([' ', ','], ['', '.'], (string) $v), 2);
    }

    /**
     * Build Modelo A field amounts for a calendar month.
     *
     * Sales (f01) use all ledger activity. VAT boxes (f02/f05/f06/f07) exclude
     * VAT settlement journals so clearing to 4435 does not understate the return.
     *
     * @param  array<string, mixed>  $manual
     * @return array<string, float>
     */
    public function buildReportData(int $creatorId, string $start, string $end, array $manual, bool $insideDeadline): array
    {
        $allRows = $this->vatReport->ledgerTotalsByCode($creatorId, $start, $end, false);
        $vatRows = $this->vatReport->ledgerTotalsByCode($creatorId, $start, $end, true);

        $f01 = self::sumByPrefix($allRows, ['71', '72'], 'credit');
        $f03 = $this->amount($manual, 'manual_03');
        $f04 = $this->amount($manual, 'manual_04');
        $f02 = self::sumByPrefix($vatRows, ['44331'], 'credit');
        $f05 = self::sumByPrefix($vatRows, ['44322'], 'debit');
        $f06 = self::sumByPrefix($vatRows, ['44321'], 'debit');
        $f07 = self::sumByPrefix($vatRows, ['44323'], 'debit');
        $f08 = $this->amount($manual, 'manual_08');
        $f09 = $this->amount($manual, 'manual_09');
        $f10 = $this->amount($manual, 'manual_10');

        $f12 = round($f05 + $f06 + $f07 + $f08 + $f09, 2);
        $f13 = round($f02 + $f10, 2);
        $f11 = round($f01 + $f03 + $f04, 2);
        $f14 = round(max(0.0, $f13 - $f12), 2);
        $f15 = round(max(0.0, $f12 - $f13), 2);
        $f16 = $insideDeadline ? $this->amount($manual, 'manual_16') : 0.0;
        $f17 = round(max(0.0, $f15 - $f16), 2);
        $f18 = $f14;
        $f19 = $this->amount($manual, 'manual_19');
        $f20 = round($f18 + $f19, 2);
        $f21 = $f17;

        return compact('f01', 'f02', 'f03', 'f04', 'f05', 'f06', 'f07', 'f08', 'f09', 'f10', 'f11', 'f12', 'f13', 'f14', 'f15', 'f16', 'f17', 'f18', 'f19', 'f20', 'f21');
    }

    /**
     * @param  iterable<int, object{code: mixed, debit: mixed, credit: mixed}>  $rows
     * @param  array<int, string>  $prefixes
     */
    public static function sumByPrefix(iterable $rows, array $prefixes, string $mode): float
    {
        $t = 0.0;
        foreach ($rows as $r) {
            $code = preg_replace('/\D/', '', (string) $r->code) ?? '';
            if ($code === '') {
                continue;
            }
            foreach ($prefixes as $pfx) {
                if (str_starts_with($code, $pfx)) {
                    $debit = (float) $r->debit;
                    $credit = (float) $r->credit;
                    $t += $mode === 'credit' ? ($credit - $debit) : ($debit - $credit);
                    break;
                }
            }
        }

        return round(max(0.0, $t), 2);
    }

    /**
     * Journal entry IDs that clear VAT into 4435 and/or use FY-*-VAT* references.
     *
     * @return array<int, int>
     */
    public function settlementJournalIds(int $creatorId): array
    {
        return $this->vatReport->settlementJournalIds($creatorId);
    }
}

