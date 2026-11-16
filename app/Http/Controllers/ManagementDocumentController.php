<?php

namespace App\Http\Controllers;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\ManagementSupplierCategory;
use App\Services\ManagementDocumentImport;
use App\Services\ManagementDocumentValidator;
use App\Services\ManagementReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Management Documents (Documentos) — the expense-document entry
 * screen behind the management P&L. Each document is one real expense
 * document (invoice, credit note, bank transfer) with a category
 * (Rules) and a cost centre.
 *
 * The P&L costs come from these documents + mapped manual journals
 * (see MonthlyManagementData): no system-derived costs alongside
 * them, so nothing is double-counted. Quotations are stored but
 * excluded from costs; a credit note is a document with a negative
 * amount. A cost with no document is a journal posting, not a row
 * here (the old 'manual' type was removed).
 */
class ManagementDocumentController extends Controller
{
    private function authorizeManagement(): void
    {
        $user = Auth::user();
        if ($user && ($user->can('menu.dashboard.accounting.reports.management.management_documents') || $user->can('manage report'))) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    public function index(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $year = (int) $request->input('year', (int) date('Y'));
        $month = $request->input('month');
        $ruleId = $request->input('management_rule_id');
        $centreId = $request->input('management_cost_centre_id');
        $supplier = $request->input('supplier');
        $status = $request->input('status');
        $type = $request->input('doc_type');

        $docs = $this->filteredQuery($request, $creatorId)
            ->with(['rule', 'costCentre'])
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Sums over the WHOLE filtered set (not just this page).
        // Net = BASE when present, else TOTAL (see netValue()).
        $sums = $this->filteredQuery($request, $creatorId)
            ->selectRaw('COALESCE(SUM(base), 0) as base_sum, COALESCE(SUM(iva), 0) as iva_sum, COALESCE(SUM(total), 0) as total_sum')
            ->selectRaw("COALESCE(SUM(CASE WHEN base IS NOT NULL THEN base ELSE total END), 0) as net_sum")
            ->first();

        return view('management-documents.index', [
            'docs' => $docs,
            'total' => $docs->total(),
            'sums' => $sums,
            'year' => $year,
            'month' => $month,
            'ruleId' => $ruleId,
            'centreId' => $centreId,
            'supplier' => $supplier,
            'status' => $status,
            'type' => $type,
            'rules' => ManagementRule::where('created_by', $creatorId)->orderBy('sort_order')->get(),
            'centres' => ManagementCostCentre::where('created_by', $creatorId)->orderBy('code')->get(),
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    /** The filtered document query (shared by the list + the export). */
    private function filteredQuery(Request $request, int $creatorId)
    {
        $year = (int) $request->input('year', (int) date('Y'));
        $month = $request->input('month');
        $ruleId = $request->input('management_rule_id');
        $centreId = $request->input('management_cost_centre_id');
        $supplier = $request->input('supplier');
        $status = $request->input('status');
        $type = $request->input('doc_type');

        return ManagementDocument::where('created_by', $creatorId)
            ->when($year, fn ($q) => $q->whereYear('doc_date', $year))
            ->when($month, fn ($q) => $q->whereMonth('doc_date', $month))
            ->when($ruleId, fn ($q) => $q->where('management_rule_id', $ruleId))
            ->when($centreId, fn ($q) => $q->where('management_cost_centre_id', $centreId))
            ->when($supplier, fn ($q) => $q->where('supplier_name', 'like', '%' . $supplier . '%'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('doc_type', $type));
    }

    public function create()
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        return view('management-documents.create', $this->formData($creatorId));
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $this->validateData($request, $creatorId);
        $data['created_by'] = $creatorId;
        ManagementDocument::create($data);

        return redirect()->route('management-documents.index')
            ->with('success', __('Document created successfully.'));
    }

    public function edit(ManagementDocument $managementDocument)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementDocument, $creatorId);

        return view('management-documents.edit', array_merge(
            $this->formData($creatorId),
            ['doc' => $managementDocument]
        ));
    }

    public function update(Request $request, ManagementDocument $managementDocument)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementDocument, $creatorId);

        $data = $this->validateData($request, $creatorId, $managementDocument->id);
        $managementDocument->update($data);

        return redirect()->route('management-documents.index')
            ->with('success', __('Document updated successfully.'));
    }

    public function destroy(ManagementDocument $managementDocument)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementDocument, $creatorId);

        $managementDocument->delete();

        return redirect()->route('management-documents.index')
            ->with('success', __('Document deleted successfully.'));
    }

    private function formData(int $creatorId): array
    {
        // Seeding is opt-in (Rules page buttons): a company with no
        // rules or centres stays empty until it loads the starters.
        $rules = ManagementRule::where('created_by', $creatorId)
            ->orderBy('sort_order')
            ->get();
        $centres = ManagementCostCentre::where('created_by', $creatorId)
            ->orderBy('code')
            ->get();

        return [
            'rules' => $rules,
            // Categories grouped by P&L group for the <optgroup> select.
            'rulesByGroup' => $rules->groupBy('group'),
            'centres' => $centres,
            // Suppliers already used in this company's documents,
            // for the supplier autocomplete (free text still works
            // for a new supplier).
            'suppliers' => ManagementDocument::where('created_by', $creatorId)
                ->whereNotNull('supplier_name')
                ->where('supplier_name', '!=', '')
                ->distinct()
                ->orderBy('supplier_name')
                ->pluck('supplier_name'),
            'defaults' => ManagementSupplierCategory::where('created_by', $creatorId)->get(),
        ];
    }

    private function ensureOwned(ManagementDocument $doc, int $creatorId): void
    {
        if ((int) $doc->created_by !== $creatorId) {
            abort(404);
        }
    }

    /**
     * Validation shared with the import — one rule set everywhere
     * (see ManagementDocumentValidator).
     */
    private function validateData(Request $request, int $creatorId, ?int $ignoreId = null): array
    {
        return ManagementDocumentValidator::validate(
            $request->all(),
            $creatorId,
            $ignoreId
        );
    }

    /** Import documents from an uploaded CSV or .xlsx file. */
    public function import(Request $request)
    {
        $this->authorizeManagement();
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'max:5120'],
        ]);
        $creatorId = \Auth::user()->creatorId();
        $file = $request->file('file');
        $result = ManagementDocumentImport::import($creatorId, $file->getRealPath(), $file->getClientOriginalExtension());

        $message = __('Imported :count document(s).', ['count' => $result['imported']]);
        if ($result['skipped'] !== []) {
            $message .= ' ' . __('Skipped :count row(s).', ['count' => count($result['skipped'])]);
        }

        return redirect()
            ->route('management-documents.index')
            ->with('success', $message)
            ->with('importSkipped', $result['skipped']);
    }

    /** Export the currently filtered document list as .xlsx. */
    public function export(Request $request)
    {
        $this->authorizeManagement();
        $docs = $this->filteredQuery($request, \Auth::user()->creatorId())
            ->with(['rule', 'costCentre'])
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return ManagementReportExport::download(
            ManagementReportExport::documents($docs),
            'management-documents-' . date('Ymd') . '.xlsx'
        );
    }
}
