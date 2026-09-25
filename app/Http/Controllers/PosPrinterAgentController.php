<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosAgentPrinter;
use App\Services\PosPrinterAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosPrinterAgentController extends Controller
{
    public function agentPrinters(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (! $branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }

        if (! Auth::user()->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $printers = PosAgentPrinter::where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->orderBy('display_name')
            ->orderBy('system_name')
            ->get()
            ->map(static function (PosAgentPrinter $p) {
                return [
                    'id' => $p->id,
                    'system_name' => $p->system_name,
                    'display_name' => $p->display_name ?: $p->system_name,
                    'last_seen_at' => optional($p->last_seen_at)->toDateTimeString(),
                ];
            })
            ->values();

        $config = PosPrinterAgentService::getConfig($branchId, $creatorId);

        return response()->json([
            'success' => true,
            'printers' => $printers,
            'config' => [
                'kitchen_printer_id' => $config ? $config->kitchen_printer_id : null,
                'bar_printer_id' => $config ? $config->bar_printer_id : null,
            ],
            'agent' => PosPrinterAgentService::agentStatusForBranch($branchId, $creatorId),
        ]);
    }

    public function agentStatus(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (! $branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }

        if (! Auth::user()->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        return response()->json([
            'success' => true,
            'agent' => PosPrinterAgentService::agentStatusForBranch($branchId, $creatorId),
        ]);
    }

    public function savePrintConfig(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'kitchen_printer_id' => 'nullable|integer',
            'bar_printer_id' => 'nullable|integer',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (! $branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }

        if (! Auth::user()->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $kitchenId = $request->filled('kitchen_printer_id') ? (int) $request->kitchen_printer_id : null;
        $barId = $request->filled('bar_printer_id') ? (int) $request->bar_printer_id : null;

        if ($kitchenId === 0) {
            $kitchenId = null;
        }
        if ($barId === 0) {
            $barId = null;
        }

        $config = PosPrinterAgentService::saveConfig($branchId, $creatorId, $kitchenId, $barId);

        return response()->json([
            'success' => true,
            'message' => __('Printer configuration saved.'),
            'config' => [
                'kitchen_printer_id' => $config->kitchen_printer_id,
                'bar_printer_id' => $config->bar_printer_id,
            ],
        ]);
    }
}
