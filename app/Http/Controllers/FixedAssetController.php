<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\Bill;
use App\Models\BillProduct;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetAcquisitionCost;
use App\Models\FixedAssetCapitalization;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\FixedAssetLocation;
use App\Models\Purchase;
use App\Models\PurchaseDeliveryNote;
use App\Models\PurchaseDeliveryNoteItem;
use App\Models\PurchaseProduct;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Asset Register — Phase 0 read-only foundation, Phase 1
 * registration (New Asset form, §7 fields) wired to
 * FixedAssetAcquisitionService::createAsset (allocateAndCreate).
 *
 * The registration / acquisition / capitalization workflow
 * itself lives in FixedAssetAcquisitionController; this
 * controller owns the register screens.
 */
class FixedAssetController extends Controller
{
    public function __construct(
        protected FixedAssetService $service,
        protected FixedAssetAcquisitionService $acquisitions,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $assets = FixedAsset::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)
                        ->orWhere('code', 'like', $search)
                        ->orWhere('serial_number', 'like', $search);
                });
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->input('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->input('location_id')))
            ->with(['category', 'location', 'costCentre'])
            ->orderBy('code')
            ->paginate(20);

        $categories = FixedAssetCategory::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);
        $locations = FixedAssetLocation::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);

        return view('fixed-assets.index', compact('assets', 'categories', 'locations'));
    }

    public function show(FixedAsset $asset)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($asset, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $asset->load(['category', 'location', 'branch', 'department', 'costCentre', 'custodian', 'parent', 'components', 'documents', 'approvals', 'statusHistory', 'auditEvents']);
        // Phase 1 tables are guarded: between a code pull and the
        // migrate that creates them, the register must still render
        // (Contract P3 precedent).
        $acquisition = null;
        $capitalization = null;
        $schedule = collect();
        $accountingEntries = collect();
        if (Schema::hasTable('fixed_asset_acquisitions')) {
            $acquisition = $this->acquisitions->latestAcquisition($asset, $creatorId);
        }
        if ($acquisition && Schema::hasTable('fixed_asset_capitalizations')) {
            $capitalization = FixedAssetCapitalization::where('created_by', $creatorId)->where('asset_id', $asset->id)->orderByDesc('id')->first();
        }
        if (Schema::hasTable('fixed_asset_depreciation_schedules')) {
            $schedule = FixedAssetDepreciationSchedule::where('created_by', $creatorId)->where('asset_id', $asset->id)->orderBy('period')->get();
        }
        if (Schema::hasTable('fixed_asset_accounting_entries')) {
            $accountingEntries = FixedAssetAccountingEntry::where('created_by', $creatorId)->where('asset_id', $asset->id)->orderByDesc('id')->get();
        }
        // Phase 2 tables are guarded the same way (Contract P3
        // precedent): between a code pull and the migrate that
        // creates them, the register must still render.
        $assignments = collect();
        $transfers = collect();
        if (Schema::hasTable('fixed_asset_assignments')) {
            $assignments = \App\Models\FixedAssetAssignment::where('created_by', $creatorId)->where('asset_id', $asset->id)->with(['custodian', 'location', 'costCentre'])->orderByDesc('id')->get();
        }
        if (Schema::hasTable('fixed_asset_transfers')) {
            $transfers = \App\Models\FixedAssetTransfer::where('created_by', $creatorId)->where('asset_id', $asset->id)->with(['toLocation', 'toCustodian', 'toCostCentre'])->orderByDesc('id')->get();
        }
        $timeline = $this->buildTimeline($asset, $assignments, $transfers);

        return view('fixed-assets.show', compact('asset', 'acquisition', 'capitalization', 'schedule', 'accountingEntries', 'assignments', 'transfers', 'timeline'));
    }

    /**
     * One merged history timeline (Phase 2): assignments +
     * transfers + status changes, newest first. Each entry is
     * ['at' => Carbon|null, 'title' => string, 'detail' => string].
     */
    protected function buildTimeline(FixedAsset $asset, $assignments, $transfers): array
    {
        $entries = [];

        foreach ($asset->statusHistory as $history) {
            $entries[] = [
                'at' => $history->changed_at,
                'title' => __('Status change'),
                'detail' => ($history->from_status ? ucfirst(str_replace('_', ' ', $history->from_status)).' → ' : '').ucfirst(str_replace('_', ' ', $history->to_status)).($history->reason ? ' ('.$history->reason.')' : ''),
            ];
        }

        foreach ($assignments as $assignment) {
            $custodian = $assignment->custodian ? $assignment->custodian->name : '—';
            $place = $assignment->location ? $assignment->location->code : null;
            $steps = [
                'requested_at' => __('Assignment requested'),
                'approved_at' => __('Assignment approved'),
                'handed_over_at' => __('Assignment handed over'),
                'accepted_at' => __('Assignment accepted'),
                'refused_at' => __('Assignment refused'),
                'returned_at' => __('Assignment returned'),
            ];
            foreach ($steps as $field => $title) {
                if ($assignment->$field) {
                    $detail = $custodian.($place ? ' · '.$place : '');
                    if ($field === 'refused_at' && $assignment->refusal_reason) {
                        $detail .= ' ('.$assignment->refusal_reason.')';
                    }
                    if ($field === 'returned_at' && $assignment->return_reason) {
                        $detail .= ' ('.$assignment->return_reason.')';
                    }
                    $entries[] = ['at' => $assignment->$field, 'title' => $title, 'detail' => $detail];
                }
            }
        }

        foreach ($transfers as $transfer) {
            $to = $transfer->toLocation ? $transfer->toLocation->code : null;
            $who = $transfer->toCustodian ? $transfer->toCustodian->name : null;
            $steps = [
                'requested_at' => __('Transfer requested'),
                'approved_at' => __('Transfer approved'),
                'handed_over_at' => __('Transfer handed over'),
                'received_at' => __('Transfer received'),
            ];
            foreach ($steps as $field => $title) {
                if ($transfer->$field) {
                    $detail = trim(($to ? $to : '').($who ? ' · '.$who : ''), ' ·');
                    $entries[] = ['at' => $transfer->$field, 'title' => $title, 'detail' => $detail ?: '—'];
                }
            }
            if ($transfer->status === \App\Models\FixedAssetTransfer::STATUS_REJECTED && $transfer->updated_at) {
                $entries[] = ['at' => $transfer->updated_at, 'title' => __('Transfer rejected'), 'detail' => $transfer->reason ?? '—'];
            }
            if ($transfer->status === \App\Models\FixedAssetTransfer::STATUS_CANCELLED && $transfer->updated_at) {
                $entries[] = ['at' => $transfer->updated_at, 'title' => __('Transfer cancelled'), 'detail' => $transfer->reason ?? '—'];
            }
        }

        usort($entries, function ($a, $b) {
            $atA = $a['at'] ? $a['at']->timestamp : 0;
            $atB = $b['at'] ? $b['at']->timestamp : 0;

            return $atB <=> $atA;
        });

        return $entries;
    }

    public function create()
    {
        if (! Auth::user()->can('create fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('fixed-assets.form', array_merge($this->formData($creatorId), [
            'asset' => new FixedAsset([
                'acquisition_method' => FixedAsset::ACQUISITION_PURCHASE,
                'currency' => FixedAssetSettings::baseCurrency($creatorId),
                'exchange_rate' => '1.0000000000',
                'life_unit' => 'year',
                'status' => FixedAsset::STATUS_DRAFT,
            ]),
            'acquisition' => new FixedAssetAcquisition([
                'source_type' => FixedAssetAcquisition::SOURCE_DIRECT,
                'currency' => FixedAssetSettings::baseCurrency($creatorId),
                'exchange_rate' => '1.0000000000',
            ]),
            'costs' => [],
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request, $creatorId);

        try {
            if ($data['source_type'] !== FixedAssetAcquisition::SOURCE_DIRECT
                && $data['source_type'] !== FixedAssetAcquisition::SOURCE_DONATION
                && $data['source_type'] !== FixedAssetAcquisition::SOURCE_OPENING_BALANCE
                && ! empty($data['source_id'])
            ) {
                $result = $this->acquisitions->createFromSource($data['source_type'], (int) $data['source_id'], $data, $creatorId, (int) Auth::id());
            } else {
                $result = $this->acquisitions->createAsset($data, $creatorId, (int) Auth::id());
            }
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $first = $result['assets'][0];
        $message = count($result['assets']) > 1
            ? __(':count assets registered.', ['count' => count($result['assets'])])
            : __('Asset :code registered as draft.', ['code' => $first->code]);

        return redirect()->route('fixed-assets.show', $first->id)->with('success', $message);
    }

    public function edit(FixedAsset $asset)
    {
        if (! Auth::user()->can('edit fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->service->assertSameCompany($asset, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $acquisition = $this->acquisitions->latestAcquisition($asset, $creatorId);
        if (! $acquisition || ! $acquisition->isEditable() || ! $asset->isDraft()) {
            return redirect()->route('fixed-assets.show', $asset->id)->with('error', __('Only a draft or returned asset can be edited.'));
        }

        return view('fixed-assets.form', array_merge($this->formData($creatorId), [
            'asset' => $asset,
            'acquisition' => $acquisition,
            'costs' => $acquisition->costs()->get()->keyBy('component_type')->all(),
        ]));
    }

    public function update(Request $request, FixedAsset $asset)
    {
        if (! Auth::user()->can('edit fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request, $creatorId);

        try {
            $asset = $this->acquisitions->updateAsset($asset, $data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-assets.show', $asset->id)->with('success', __('Asset successfully updated.'));
    }

    /**
     * Printable asset label (spec §10): company name, asset
     * name, code, QR code, category, department, location and
     * custodian. Scanning the QR opens the asset profile
     * (subject to permission — the show route requires auth).
     * Label size comes from the company settings (D8).
     */
    public function label(FixedAsset $asset)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($asset, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $asset->load(['category', 'location', 'department', 'costCentre', 'custodian']);

        $dimensions = FixedAssetSettings::labelDimensions($creatorId);
        $profileUrl = route('fixed-assets.show', $asset);

        return view('fixed-assets.label', compact('asset', 'dimensions', 'profileUrl'));
    }

    // ------------------------------------------------------------------
    // Form
    // ------------------------------------------------------------------

    protected function formData(int $creatorId): array
    {
        $categories = FixedAssetCategory::forCompany($creatorId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'default_useful_life', 'default_life_unit']);
        $locations = FixedAssetLocation::forCompany($creatorId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $departments = Department::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $costCentres = CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']);
        $employees = Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $accounts = ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(['id', 'code', 'name']);
        $parents = FixedAsset::forCompany($creatorId)->where('is_component', false)->orderBy('code')->get(['id', 'code', 'name']);

        // Recent purchasing sources for the "acquisition from"
        // picker: PO lines, bill lines and delivery-note items.
        // NOTE: PurchaseProduct / BillProduct have NO purchase() /
        // bill() relation in this app, so the parents are looked
        // up by id and passed as maps (never via with()/whereHas()
        // on a relation that does not exist). The delivery-note
        // item relation is note().
        $purchases = Purchase::where('created_by', $creatorId)->with('vender')->orderByDesc('id')->limit(50)->get();
        $purchaseProducts = PurchaseProduct::whereIn('purchase_id', $purchases->pluck('id'))->with('product')->orderByDesc('id')->limit(50)->get();
        $purchaseMap = $purchases->keyBy('id');
        $bills = Bill::where('created_by', $creatorId)->with('vender')->orderByDesc('id')->limit(50)->get();
        $billProducts = BillProduct::whereIn('bill_id', $bills->pluck('id'))->with('product')->orderByDesc('id')->limit(50)->get();
        $billMap = $bills->keyBy('id');
        $deliveryNotes = PurchaseDeliveryNote::where('created_by', $creatorId)->with('vender')->orderByDesc('id')->limit(50)->get();
        $deliveryItems = PurchaseDeliveryNoteItem::whereIn('purchase_delivery_note_id', $deliveryNotes->pluck('id'))->with(['note', 'product'])->orderByDesc('id')->limit(50)->get();
        $deliveryNoteMap = $deliveryNotes->keyBy('id');

        return compact('categories', 'locations', 'branches', 'departments', 'costCentres', 'employees', 'accounts', 'parents', 'purchaseProducts', 'purchaseMap', 'billProducts', 'billMap', 'deliveryItems', 'deliveryNoteMap');
    }

    protected function validateForm(Request $request, int $creatorId): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode_value' => ['nullable', 'string', 'max:255'],
            'parent_asset_id' => ['nullable', 'integer'],
            'acquisition_method' => ['required', 'string'],
            'source_type' => ['required', 'string'],
            'source_id' => ['nullable', 'integer'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'po_reference' => ['nullable', 'string', 'max:100'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'receipt_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.0000000001'],
            'warranty_start' => ['nullable', 'date'],
            'warranty_end' => ['nullable', 'date'],
            'depreciation_start_date' => ['nullable', 'date'],
            'useful_life' => ['nullable', 'integer', 'min:1'],
            'life_unit' => ['nullable', 'string'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'opening_accumulated_depreciation' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'cost_centre_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'custodian_id' => ['nullable', 'integer'],
            'asset_account_id' => ['nullable', 'integer'],
            'accum_depreciation_account_id' => ['nullable', 'integer'],
            'depreciation_expense_account_id' => ['nullable', 'integer'],
            'asset_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string'],
            'components' => ['required', 'array'],
            'components.*.component_type' => ['required', 'string'],
            'components.*.gross_amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.vat_amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.vat_recoverable' => ['nullable', 'boolean'],
            'components.*.source_document_reference' => ['nullable', 'string', 'max:255'],
            'components.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Drop empty component rows (gross 0 and no VAT) except
        // the purchase price row, which the service requires.
        $data['components'] = array_values(array_filter($data['components'], function ($row) {
            $gross = (float) ($row['gross_amount'] ?? 0);
            $vat = (float) ($row['vat_amount'] ?? 0);

            return $row['component_type'] === FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE || $gross > 0 || $vat > 0;
        }));

        return $data;
    }
}
