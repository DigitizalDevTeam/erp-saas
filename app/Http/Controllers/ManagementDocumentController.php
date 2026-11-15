<?php

namespace App\Http\Controllers;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\ManagementSupplierCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Management Documents (Documentos) — the expense-document entry
 * screen behind the management P&L. Each document is one real expense
 * document (invoice, credit note, bank transfer, manual entry) with a
 * category (Rules) and a cost centre.
 *
 * The P&L costs come from these documents ONLY (see
 * MonthlyManagementData): no system-derived costs alongside them, so
 * nothing is double-counted. Quotations are stored but excluded from
 * costs; a credit note is a document with a negative amount.
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

        $docs = ManagementDocument::where('created_by', $creatorId)
            ->when($year, fn ($q) => $q->whereYear('doc_date', $year))
            ->when($month, fn ($q) => $q->whereMonth('doc_date', $month))
            ->when($ruleId, fn ($q) => $q->where('management_rule_id', $ruleId))
            ->when($centreId, fn ($q) => $q->where('management_cost_centre_id', $centreId))
            ->when($supplier, fn ($q) => $q->where('supplier_name', 'like', '%' . $supplier . '%'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('doc_type', $type))
            ->with(['rule', 'costCentre'])
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('management-documents.index', [
            'docs' => $docs,
            'total' => $docs->total(),
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

        $data = $this->validateData($request, $creatorId);
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
        ManagementRule::ensureSeeded($creatorId);
        ManagementCostCentre::ensureSeeded($creatorId);

        return [
            'rules' => ManagementRule::where('created_by', $creatorId)->orderBy('sort_order')->get(),
            'centres' => ManagementCostCentre::where('created_by', $creatorId)->orderBy('code')->get(),
        ];
    }

    private function ensureOwned(ManagementDocument $doc, int $creatorId): void
    {
        if ((int) $doc->created_by !== $creatorId) {
            abort(404);
        }
    }

    /**
     * Same validation as the erpsaaspro API (ManagementDocumentController
     * in erpsaaspro), adapted to Blade + the app's creatorId() scoping.
     *
     * One cross-field rule (the per-rule `where()` takes only
     * (column, value) — a third argument is silently dropped, so the
     * "not equal" form is never used here): the rule must belong to
     * this company, enforced by where('created_by', $creatorId).
     */
    private function validateData(Request $request, int $creatorId): array
    {
        $validator = Validator::make($request->all(), [
            'doc_date' => ['required', 'date'],
            'supplier_nif' => ['nullable', 'string', 'max:50'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'doc_number' => ['nullable', 'string', 'max:100'],
            'doc_type' => ['required', 'in:' . implode(',', ManagementDocument::DOC_TYPES)],
            'base' => ['nullable', 'numeric'],
            'iva' => ['nullable', 'numeric'],
            'retention' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric'],
            'status' => ['required', 'in:' . implode(',', ManagementDocument::STATUSES)],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'management_rule_id' => [
                'nullable', 'integer',
                Rule::exists('management_rules', 'id')->where('created_by', $creatorId),
            ],
            'management_cost_centre_id' => [
                'nullable', 'integer',
                Rule::exists('management_cost_centres', 'id')->where('created_by', $creatorId),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        // A document number is required for every type EXCEPT a manual
        // entry (salaries, IRPS, VAT, employee payments have no
        // document). A credit note must be negative; every other
        // costing document must be positive (or zero).
        // TOTAL must equal BASE + IVA − retention when BASE is present.
        $validator->after(function ($validator) use ($request) {
            $data = $validator->getData();
            $type = $data['doc_type'] ?? null;
            $total = $data['total'] ?? null;

            if ($type !== 'manual' && empty($data['doc_number'])) {
                $validator->errors()->add('doc_number', __('The document number is required.'));
            }
            if ($type === 'credit_note' && $total !== null && $total >= 0) {
                $validator->errors()->add('total', __('A credit note must have a negative amount.'));
            }
            if (in_array($type, ['invoice', 'bank_transfer', 'manual'], true) && $total !== null && $total < 0) {
                $validator->errors()->add('total', __('The amount must be zero or positive.'));
            }
            // A credit note's amounts are negative (base −100, total −100);
            // every other type's base / VAT / retention must be >= 0.
            foreach (['base', 'iva', 'retention'] as $field) {
                $value = $data[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if ($type === 'credit_note' && (float) $value > 0) {
                    $validator->errors()->add($field, __('A credit note must have a negative amount.'));
                }
                if ($type !== 'credit_note' && (float) $value < 0) {
                    $validator->errors()->add($field, __('The amount must be zero or positive.'));
                }
            }
            if (isset($data['base']) && $data['base'] !== '' && $data['base'] !== null && $total !== null) {
                $expected = (float) $data['base'] + (float) ($data['iva'] ?? 0) - (float) ($data['retention'] ?? 0);
                if (abs((float) $total - $expected) > 0.01) {
                    $validator->errors()->add('total', __('The total must equal base + VAT − retention.'));
                }
            }
        });

        $data = $validator->validate();

        // Auto-assign the category from the supplier's default when the
        // user left it empty (the client: assign a category to a
        // supplier, all that supplier's documents get that category).
        if (empty($data['management_rule_id'])) {
            $default = ManagementSupplierCategory::defaultRuleFor(
                $creatorId,
                $data['supplier_name'] ?? null
            );
            if ($default) {
                $data['management_rule_id'] = $default->id;
            } else {
                throw ValidationException::withMessages([
                    'management_rule_id' => __('The category is required.'),
                ]);
            }
        }

        return $data;
    }
}
