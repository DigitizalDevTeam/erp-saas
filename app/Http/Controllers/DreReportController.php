<?php

namespace App\Http\Controllers;

use App\Exports\DreDemonstracaoResultadosExport;
use App\Services\DreDemonstracaoResultadosService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DreReportController extends Controller
{
    /**
     * Comparative "Demonstração dos Resultados" (P&L) from transaction_lines + chart of accounts.
     */
    public function index(Request $request)
    {
        if (! \Auth::user()->can('income vs expense report')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $y1 = (int) $request->get('year1', (int) date('Y'));
        $y2 = (int) $request->get('year2', $y1 - 1);
        $y1 = max(2000, min(2100, $y1));
        $y2 = max(2000, min(2100, $y2));

        $creatorId = \Auth::user()->creatorId();
        $lang = \Auth::user()->lang ?? 'en';
        $report = DreDemonstracaoResultadosService::build($creatorId, $y1, $y2);
        $filled = DreDemonstracaoResultadosService::buildFilledRows($report, $creatorId, $lang);

        return view('report.dre_demonstracao_resultados', compact('report', 'filled'));
    }

    public function export(Request $request)
    {
        if (! \Auth::user()->can('income vs expense report')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $y1 = (int) $request->get('year1', (int) date('Y'));
        $y2 = (int) $request->get('year2', $y1 - 1);
        $y1 = max(2000, min(2100, $y1));
        $y2 = max(2000, min(2100, $y2));

        $creatorId = \Auth::user()->creatorId();
        $lang = \Auth::user()->lang ?? 'en';
        $report = DreDemonstracaoResultadosService::build($creatorId, $y1, $y2);
        $filled = DreDemonstracaoResultadosService::buildFilledRows($report, $creatorId, $lang);

        $filename = 'dre_demonstracao_resultados_'.$report['year1'].'_'.$report['year2'].'.xlsx';

        return Excel::download(new DreDemonstracaoResultadosExport($report, $filled, 'MT', 'Euros'), $filename);
    }
}
