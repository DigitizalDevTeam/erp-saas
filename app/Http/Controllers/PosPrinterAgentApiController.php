<?php

namespace App\Http\Controllers;

use App\Models\PosAgentPrinter;
use App\Models\PosPrintJob;
use App\Services\PosPrinterAgentService;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosPrinterAgentApiController extends Controller
{
    use ApiResponser;

    /**
     * Public: list restaurant branches for the agent dropdown (no login).
     */
    public function branches()
    {
        return $this->success([
            'branches' => PosPrinterAgentService::listAgentBranches(),
        ], 'Branches loaded');
    }

    public function heartbeat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|exists:branches,id',
            'machine_name' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $branchId = (int) $request->branch_id;
        $branch = PosPrinterAgentService::resolveBranch($branchId);
        if (! $branch) {
            return $this->error(__('Invalid branch.'), 422);
        }

        $creatorId = (int) $branch->created_by;
        PosPrinterAgentService::recordHeartbeat(
            $branchId,
            $creatorId,
            $request->get('machine_name')
        );

        return $this->success([
            'branch_id' => $branchId,
            'branch_name' => $branch->name,
            'status' => 'online',
            'server_time' => now()->toIso8601String(),
        ], 'Heartbeat ok');
    }

    public function syncPrinters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|exists:branches,id',
            'printers' => 'required|array',
            'printers.*.system_name' => 'required|string|max:255',
            'printers.*.display_name' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $branchId = (int) $request->branch_id;
        $branch = PosPrinterAgentService::resolveBranch($branchId);
        if (! $branch) {
            return $this->error(__('Invalid branch.'), 422);
        }

        $creatorId = (int) $branch->created_by;
        PosPrinterAgentService::recordHeartbeat($branchId, $creatorId, $request->get('machine_name'));
        $synced = PosPrinterAgentService::syncPrinters($branchId, $creatorId, $request->printers);

        return $this->success([
            'count' => count($synced),
            'printers' => collect($synced)->map(static function (PosAgentPrinter $p) {
                return [
                    'id' => $p->id,
                    'system_name' => $p->system_name,
                    'display_name' => $p->display_name,
                    'last_seen_at' => optional($p->last_seen_at)->toIso8601String(),
                ];
            })->values(),
        ], 'Printers synced');
    }

    public function jobs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|exists:branches,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $branchId = (int) $request->branch_id;
        $branch = PosPrinterAgentService::resolveBranch($branchId);
        if (! $branch) {
            return $this->error(__('Invalid branch.'), 422);
        }

        $creatorId = (int) $branch->created_by;
        PosPrinterAgentService::recordHeartbeat($branchId, $creatorId, $request->get('machine_name'));
        $jobs = PosPrinterAgentService::claimPendingJobs($branchId, $creatorId, 20);

        return $this->success([
            'jobs' => collect($jobs)->map(static function (PosPrintJob $job) {
                return [
                    'id' => $job->id,
                    'zone' => $job->zone,
                    'system_printer_name' => $job->system_printer_name,
                    'title' => $job->title,
                    'payload' => $job->payload,
                    'pos_id' => $job->pos_id,
                    'status' => $job->status,
                ];
            })->values(),
        ], 'Jobs claimed');
    }

    public function ack(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:done,failed',
            'error_message' => 'nullable|string|max:2000',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $query = PosPrintJob::where('id', (int) $id);
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        $job = $query->first();

        if (! $job) {
            return $this->error(__('Job not found.'), 404);
        }

        PosPrinterAgentService::acknowledgeJob(
            $job,
            (string) $request->status,
            $request->get('error_message')
        );

        return $this->success([
            'id' => $job->id,
            'status' => $job->status,
        ], 'Job acknowledged');
    }
}
