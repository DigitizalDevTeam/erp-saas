<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosAgentPrinter;
use App\Services\PosActorService;
use App\Services\PosPrinterAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosPrinterAgentController extends Controller
{
    public function agentPrinters(Request $request)
    {
        $printerActor = PosActorService::restaurantActor();
        if (! Auth::user()->canManagePrinters() && ! ($printerActor && $printerActor->canManagePrinters())) {
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
                'default_printer_id' => $config ? $config->default_printer_id : null,
                'use_print_agent_for_invoice' => PosPrinterAgentService::usesPrintAgentForInvoice($config),
                'save_image_when_offline' => PosPrinterAgentService::saveImageWhenOfflineEnabled($config),
            ],
            'agent' => PosPrinterAgentService::agentStatusForBranch($branchId, $creatorId),
        ]);
    }

    public function agentStatus(Request $request)
    {
        $printerActor = PosActorService::restaurantActor();
        if (! Auth::user()->canManagePrinters() && ! ($printerActor && $printerActor->canManagePrinters())) {
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
        $actor = PosActorService::restaurantActor();
        $loggedInUser = Auth::user();
        $canChangePrintSettings = ($actor && $actor->canManagePrinters())
            || ($loggedInUser && $loggedInUser->canManagePrinters());
        if (! $canChangePrintSettings) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'kitchen_printer_id' => 'nullable|integer',
            'bar_printer_id' => 'nullable|integer',
            'default_printer_id' => 'nullable|integer',
            'use_print_agent_for_invoice' => 'nullable|boolean',
            'save_image_when_offline' => 'nullable|boolean',
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
        $defaultId = $request->filled('default_printer_id') ? (int) $request->default_printer_id : null;

        if ($kitchenId === 0) {
            $kitchenId = null;
        }
        if ($barId === 0) {
            $barId = null;
        }
        if ($defaultId === 0) {
            $defaultId = null;
        }

        $useAgentInvoice = $request->boolean('use_print_agent_for_invoice');
        $saveImageOffline = $request->boolean('save_image_when_offline');

        $config = PosPrinterAgentService::saveConfig(
            $branchId,
            $creatorId,
            $kitchenId,
            $barId,
            $defaultId,
            $useAgentInvoice,
            $saveImageOffline
        );

        return response()->json([
            'success' => true,
            'message' => __('Printer configuration saved.'),
            'config' => [
                'kitchen_printer_id' => $config->kitchen_printer_id,
                'bar_printer_id' => $config->bar_printer_id,
                'default_printer_id' => $config->default_printer_id,
                'use_print_agent_for_invoice' => PosPrinterAgentService::usesPrintAgentForInvoice($config),
                'save_image_when_offline' => PosPrinterAgentService::saveImageWhenOfflineEnabled($config),
            ],
        ]);
    }

    /**
     * Enqueue a bill/receipt print job to the Print Agent default printer.
     */
    public function printReceipt(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.'), 'fallback' => false], 403);
        }

        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'pos_id' => 'nullable|integer',
            'pos_table_id' => 'nullable|integer',
            'pos_shift_id' => 'nullable|integer',
            'pos_order_type' => 'nullable|string',
            'image_base64' => 'nullable|string',
        ]);

        $user = Auth::user();
        $creatorId = $user->creatorId();
        $posId = (int) $request->get('pos_id');
        $tableId = (int) $request->get('pos_table_id');
        $shiftId = (int) $request->get('pos_shift_id');
        $orderType = $request->get('pos_order_type');
        $branchId = (int) $request->get('branch_id');

        $pos = null;
        if ($posId > 0) {
            $pos = \App\Models\Pos::where('id', $posId)
                ->where('created_by', $creatorId)
                ->with(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch'])
                ->first();
        } elseif ($tableId > 0 && $shiftId > 0) {
            $pos = \App\Models\Pos::where('table_id', $tableId)
                ->where('shift_id', $shiftId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch'])
                ->first();
        } elseif ($shiftId > 0 && in_array($orderType, [\App\Models\Pos::ORDER_TYPE_DELIVERY, \App\Models\Pos::ORDER_TYPE_TAKEAWAY], true)) {
            $q = \App\Models\Pos::whereNull('table_id')
                ->where('order_type', $orderType)
                ->where('shift_id', $shiftId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch']);
            $pos = $q->latest('id')->first();
        }

        if (! $pos) {
            return response()->json([
                'error' => __('No order found to print.'),
                'fallback' => false,
            ], 404);
        }

        if ($branchId <= 0) {
            $branchId = (int) ($pos->branch_id ?? 0);
        }

        if ($branchId <= 0) {
            return response()->json([
                'error' => __('Please select a branch.'),
                'fallback' => false,
            ], 422);
        }

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (! $branch) {
            return response()->json(['error' => __('Invalid branch.'), 'fallback' => false], 422);
        }

        if (! $user->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.'), 'fallback' => false], 403);
        }

        $config = PosPrinterAgentService::getConfig($branchId, $creatorId);
        if (! $config || ! $config->default_printer_id) {
            return response()->json([
                'error' => __('No default printer configured. Set “Default Printer for Other Prints” in Printer Settings.'),
                'fallback' => false,
            ], 422);
        }

        if (! PosPrinterAgentService::usesPrintAgentForInvoice($config)) {
            return response()->json([
                'error' => __('pos.print_agent_invoice_disabled'),
                'fallback' => true,
            ], 422);
        }

        if (PosPrinterAgentService::saveImageWhenOfflineEnabled($config)
            && PosPrinterAgentService::printerAgentOffline($branchId, $creatorId)) {
            $saved = PosPrinterAgentService::saveReceiptImageForPos($pos, $user);
            if ($saved) {
                return response()->json([
                    'success' => true,
                    'saved_as_image' => true,
                    'image_url' => $saved['url'],
                    'message' => __('pos.receipt_saved_as_image'),
                    'fallback' => false,
                ]);
            }
        }

        try {
            $job = PosPrinterAgentService::enqueueReceiptForPosIfEnabled($pos, $user);
        } catch (\Throwable $e) {
            \Log::warning('POS agent receipt enqueue failed: ' . $e->getMessage());

            return response()->json([
                'error' => __('Could not send to printer agent.'),
                'fallback' => false,
            ], 500);
        }

        if (! $job) {
            return response()->json([
                'error' => __('No default printer configured.'),
                'fallback' => false,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'message' => __('Sent to printer agent.'),
            'fallback' => false,
        ]);
    }
}
