<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Services\KitchenReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Kitchen Production — Reports + dashboard (Phase 7, plan §8/§9).
 *
 * One screen per report, each with its date/period filter, and CSV /
 * Excel / PDF export of the same data the screen shows. Everything
 * is gated by 'view kitchen reports' and scoped by creatorId — the
 * service never sees another company's rows.
 *
 * No new business feature lives here: reports only read the data
 * Phases 0–6 already record.
 */
class KitchenReportController extends Controller
{
    public const EXPORT_CSV = 'csv';
    public const EXPORT_XLSX = 'xlsx';
    public const EXPORT_PDF = 'pdf';

    public const EXPORT_FORMATS = [
        self::EXPORT_CSV,
        self::EXPORT_XLSX,
        self::EXPORT_PDF,
    ];

    public function index()
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('kitchen.reports.index', [
            'reports' => KitchenReportService::REPORTS,
        ]);
    }

    public function dashboard(KitchenReportService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('kitchen.reports.dashboard', $service->dashboard((int) \Auth::user()->creatorId()));
    }

    public function show(string $report, Request $request, KitchenReportService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(KitchenReportService::REPORTS[$report])) {
            abort(404);
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $filters = $this->filters($request);
        $data = $service->build($report, $creatorId, $filters);

        $detail = null;
        if ($report === KitchenReportService::REPORT_TRACEABILITY && $request->filled('batch_id')) {
            $batch = KitchenProductionBatch::where('id', (int) $request->input('batch_id'))
                ->where('created_by', $creatorId)
                ->with(['order.lines.ingredient', 'order.lines.lotUsages.lot', 'product'])
                ->first();

            if ($batch) {
                $detail = array_merge(['batch' => $batch], $service->traceabilityDetail($batch, $creatorId));
            }
        }

        return view('kitchen.reports.show', array_merge($data, [
            'filters' => $filters,
            'filterFields' => KitchenReportService::FILTER_FIELDS[$report],
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(),
            'warehouses' => \App\Models\warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'sectionOptions' => KitchenProductionOrder::SECTIONS,
            'categories' => \App\Models\KitchenLoss::CATEGORIES,
            'statuses' => $this->statusOptions($report),
            'detail' => $detail,
            'traceabilityBatches' => $report === KitchenReportService::REPORT_TRACEABILITY
                ? KitchenProductionBatch::where('created_by', $creatorId)->orderByDesc('created_at')->limit(100)->get(['id', 'batch_number'])
                : [],
        ]));
    }

    public function export(string $report, string $format, Request $request, KitchenReportService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(KitchenReportService::REPORTS[$report]) || ! in_array($format, self::EXPORT_FORMATS, true)) {
            abort(404);
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $data = $service->build($report, $creatorId, $this->filters($request));
        $filename = 'kitchen-'.$report.'-'.date('Y-m-d');

        if ($format === self::EXPORT_CSV) {
            return $this->exportCsv($data, $filename);
        }

        if ($format === self::EXPORT_XLSX) {
            return Excel::download(
                new \App\Exports\KitchenReportExport($data['headings'], $data['rows']),
                $filename.'.xlsx'
            );
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('kitchen.reports.pdf', [
            'title' => $data['title'],
            'description' => $data['description'],
            'headings' => $data['headings'],
            'rows' => $data['rows'],
            'totals' => $data['totals'],
        ])->download($filename.'.pdf');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $filters = [
            'from' => $request->input('from') ?: date('Y-m-01'),
            'to' => $request->input('to') ?: date('Y-m-t'),
        ];

        foreach (['branch_id', 'warehouse_id', 'section', 'category', 'status', 'search', 'group'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        if (! empty($filters['branch_id'])) {
            $filters['branch_id'] = (int) $filters['branch_id'];
        }

        if (! empty($filters['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $filters['warehouse_id'];
        }

        return $filters;
    }

    protected function statusOptions(string $report): array
    {
        return match ($report) {
            KitchenReportService::REPORT_PRODUCTION => KitchenProductionOrder::STATUSES,
            KitchenReportService::REPORT_LOSSES => \App\Models\KitchenLoss::STATUSES,
            KitchenReportService::REPORT_REQUISITIONS => \App\Models\KitchenRequisition::STATUSES,
            default => [],
        };
    }

    protected function exportCsv(array $data, string $filename)
    {
        $headings = $data['headings'];
        $rows = $data['rows'];

        return response()->streamDownload(function () use ($headings, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headings);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }
}
