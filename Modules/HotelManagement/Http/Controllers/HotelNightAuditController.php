<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Utility;
use App\Services\BusinessDayLockService;
use App\Services\NightAuditOrchestratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelNightAudit;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelNightAuditPreviewService;

class HotelNightAuditController extends Controller
{
    public function __construct(private readonly HotelNightAuditPreviewService $previewService)
    {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewNightAudit(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageNightAudit(Auth::user());
    }

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $branchId = Auth::user()->activeBranchContextId();
        $auditDate = $request->get('audit_date', date('Y-m-d', strtotime('-1 day')));
        $settings = Utility::settingsById($creatorId);
        $nightAuditTime = NightAuditOrchestratorService::configuredNightAuditTime($creatorId);

        $preview = $this->previewService->build($creatorId, $auditDate, $branchId);

        $audits = HotelNightAudit::where('created_by', $creatorId)
            ->orderByDesc('audit_date')
            ->orderByDesc('id')
            ->get();
        $canManage = $this->canManage();

        return view('hotelmanagement::night_audit.index', compact(
            'audits',
            'canManage',
            'nightAuditTime',
            'settings',
            'preview',
            'auditDate'
        ));
    }

    public function previewJson(Request $request)
    {
        if (! $this->canView()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate(['audit_date' => 'required|date']);

        $preview = $this->previewService->build(
            (int) Auth::user()->creatorId(),
            $request->audit_date,
            Auth::user()->activeBranchContextId()
        );

        return response()->json($preview);
    }

    public function export(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $auditDate = $request->get('audit_date', date('Y-m-d', strtotime('-1 day')));
        $preview = $this->previewService->build(
            (int) Auth::user()->creatorId(),
            $auditDate,
            Auth::user()->activeBranchContextId()
        );

        return view('hotelmanagement::night_audit.export_print', compact('preview'));
    }

    public function run(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $auditDate = $request->get('audit_date', date('Y-m-d', strtotime('-1 day')));
        $creatorId = Auth::user()->creatorId();
        $branchId = Auth::user()->activeBranchContextId();

        if (BusinessDayLockService::isLocked($creatorId, $auditDate)) {
            return redirect()->back()->with('error', __('Night audit already completed for this date.'));
        }

        $alreadyClosed = HotelNightAudit::where('created_by', $creatorId)
            ->where('branch_id', $branchId)
            ->whereDate('audit_date', $auditDate)
            ->where('status', 'closed')
            ->exists();
        if ($alreadyClosed) {
            return redirect()->back()->with('error', __('Night audit already closed for this date.'));
        }

        $posResult = NightAuditOrchestratorService::closeBusinessDay($creatorId, $auditDate, $branchId, (int) Auth::id());

        $preview = $this->previewService->build($creatorId, $auditDate, $branchId);

        DB::transaction(function () use ($creatorId, $branchId, $auditDate, $posResult, $preview) {
            $roomRevenue = (float) ($preview['revenue']['lodging'] ?? 0);
            $serviceRevenue = round(
                (float) ($preview['revenue']['services'] ?? 0)
                + (float) ($preview['revenue']['laundry'] ?? 0)
                + (float) ($preview['revenue']['minibar'] ?? 0)
                + (float) ($preview['revenue']['other'] ?? 0),
                2
            );

            $summary = array_merge($posResult['summary'], [
                'room_revenue' => $roomRevenue,
                'service_revenue' => $serviceRevenue,
                'hotel_total' => (float) ($preview['revenue']['total'] ?? 0),
                'preview' => $preview,
            ]);

            HotelNightAudit::create([
                'branch_id' => $branchId,
                'audit_date' => $auditDate,
                'room_revenue' => $roomRevenue,
                'service_revenue' => $serviceRevenue,
                'total_revenue' => $roomRevenue + $serviceRevenue,
                'pos_sales_total' => $posResult['pos_sales_total'],
                'pos_payments_total' => $posResult['pos_payments_total'],
                'summary_json' => $summary,
                'status' => 'closed',
                'created_by' => $creatorId,
                'closed_by' => Auth::id(),
            ]);

            HotelStay::where('created_by', $creatorId)
                ->where('status', 'checked_out')
                ->whereDate('checked_out_at', '<=', $auditDate)
                ->update(['notes' => DB::raw("CONCAT(COALESCE(notes,''), ' | Audit closed')")]);
        });

        return redirect()->route('hotel.night-audit.index', ['audit_date' => $auditDate])->with(
            'success',
            __('Night audit completed. :count POS shift(s) closed. Day is locked.', ['count' => $posResult['shifts_closed']])
        );
    }
}
