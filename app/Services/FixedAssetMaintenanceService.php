<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetMaintenanceCost;
use App\Models\FixedAssetMaintenanceRequest;
use App\Models\JournalItem;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 4 — Maintenance (plan §5, §10; spec §15).
 *
 * Workflow: requested → approved → in_progress → completed →
 * verified; rejected / cancelled are terminal. Approval reuses
 * the Phase 0 FixedAssetApproval engine (new type
 * "maintenance"); the approver must differ from the requester
 * — no self-approval, at any value.
 *
 * On approval the asset moves to under_maintenance; on
 * completion (or cancel after approval) it returns to its
 * previous_status. Assignment, cost centre and depreciation
 * schedule are untouched by maintenance — maintenance is not
 * a transfer and never restarts depreciation.
 *
 * Costs are expenses, never capitalizations: recording a cost
 * posts Dr maintenance expense / Cr accounts payable through
 * JournalEntryPoster only, at recording time. The asset's
 * capitalized cost, book value and schedule are never touched
 * by a cost (proven by test).
 */
class FixedAssetMaintenanceService
{
    public function __construct(
        protected FixedAssetService $assets,
        protected FixedAssetAcquisitionService $acquisitions,
        protected JournalEntryPoster $poster,
        protected ChartAccountResolver $resolver,
    ) {
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * An asset can be maintained only while it is in service.
     * Disposed / written off / lost / stolen assets can never
     * be maintained again; neither can an asset that is not
     * capitalized yet, in transfer, already under maintenance
     * or under verification. This is a service-layer guard, not
     * only a UI rule. A damaged asset CAN be maintained — repair
     * is the point of maintenance.
     */
    public function assertMaintainable(FixedAsset $asset, int $creatorId): void
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if (in_array($asset->status, [FixedAsset::STATUS_DISPOSED, FixedAsset::STATUS_WRITTEN_OFF, FixedAsset::STATUS_LOST, FixedAsset::STATUS_STOLEN], true)) {
            throw new FixedAssetException("A {$asset->status} asset can never be maintained again.");
        }

        if (! in_array($asset->status, [
            FixedAsset::STATUS_AVAILABLE,
            FixedAsset::STATUS_ACTIVE,
            FixedAsset::STATUS_ASSIGNED,
            FixedAsset::STATUS_FULLY_DEPRECIATED,
            FixedAsset::STATUS_OUT_OF_SERVICE,
            FixedAsset::STATUS_DAMAGED,
            FixedAsset::STATUS_IMPAIRED,
        ], true)) {
            throw new FixedAssetException("Only an in-service asset can be maintained (asset is {$asset->status}).");
        }

        if ($this->activeRequestFor($asset, $creatorId)) {
            throw new FixedAssetException('This asset already has an active maintenance request.');
        }
    }

    public function activeRequestFor(FixedAsset $asset, int $creatorId): ?FixedAssetMaintenanceRequest
    {
        return FixedAssetMaintenanceRequest::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->whereIn('status', FixedAssetMaintenanceRequest::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Request + approval
    // ------------------------------------------------------------------

    public function requestMaintenance(FixedAsset $asset, array $data, int $creatorId, int $userId): FixedAssetMaintenanceRequest
    {
        $this->assertMaintainable($asset, $creatorId);

        $type = (string) ($data['type'] ?? FixedAssetMaintenanceRequest::TYPE_CORRECTIVE);
        if (! in_array($type, FixedAssetMaintenanceRequest::TYPES, true)) {
            throw new FixedAssetException('Invalid maintenance type.');
        }

        $priority = (string) ($data['priority'] ?? FixedAssetMaintenanceRequest::PRIORITY_MEDIUM);
        if (! in_array($priority, FixedAssetMaintenanceRequest::PRIORITIES, true)) {
            throw new FixedAssetException('Invalid maintenance priority.');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new FixedAssetException('A description of the fault or work is required.');
        }

        $expectedCost = null;
        if (isset($data['expected_cost']) && $data['expected_cost'] !== '' && $data['expected_cost'] !== null) {
            if (! is_numeric($data['expected_cost']) || bccomp((string) $data['expected_cost'], '0', 4) < 0) {
                throw new FixedAssetException('Expected cost must not be negative.');
            }
            $expectedCost = bcadd((string) $data['expected_cost'], '0', 4);
        }

        return DB::transaction(function () use ($asset, $type, $priority, $description, $expectedCost, $creatorId, $userId) {
            $request = FixedAssetMaintenanceRequest::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'type' => $type,
                'priority' => $priority,
                'description' => $description,
                'expected_cost' => $expectedCost,
                'status' => FixedAssetMaintenanceRequest::STATUS_REQUESTED,
                'requested_by' => $userId,
                'requested_at' => now(),
            ]);

            // One approval level, a different user than the
            // requester (enforced by decideApproval).
            $this->assets->requestApproval(
                $asset,
                FixedAssetApproval::TYPE_MAINTENANCE,
                $request->id,
                $userId,
                $expectedCost,
                1
            );

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_maintenance_request', $request->id, 'requested', $userId, null, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    /**
     * Decide the maintenance approval. Wraps the Phase 0 decision
     * (which already refuses self-approval). Approved → the
     * asset moves to under_maintenance (its previous status is
     * stored on the request so completion can restore it).
     * Rejected → the request is rejected, the asset untouched.
     * Returned → the request stays requested (the approver's
     * comment is on the approval row).
     */
    public function decideMaintenanceApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $request = $this->requestOf($approval);
        $this->assets->assertSameCompany($request, (int) $approval->created_by);

        if ($approval->transaction_type !== FixedAssetApproval::TYPE_MAINTENANCE) {
            throw new FixedAssetException('This approval is not a maintenance approval.');
        }

        $decided = $this->assets->decideApproval($approval, $approverId, $decision, $comment);

        if ($decision === FixedAssetApproval::DECISION_APPROVED) {
            DB::transaction(function () use ($request, $approverId) {
                $asset = $request->asset->fresh();
                $previousStatus = $asset->status;

                $request->update([
                    'status' => FixedAssetMaintenanceRequest::STATUS_APPROVED,
                    'previous_status' => $previousStatus,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                ]);

                $this->assets->recordStatusChange($asset, $previousStatus, FixedAsset::STATUS_UNDER_MAINTENANCE, $approverId, 'Maintenance approved');
                $this->assets->logAudit((int) $request->created_by, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'approved', $approverId, null, $request->fresh()->toArray());
            });
        }

        if ($decision === FixedAssetApproval::DECISION_REJECTED) {
            DB::transaction(function () use ($request, $approverId, $comment) {
                $request->update([
                    'status' => FixedAssetMaintenanceRequest::STATUS_REJECTED,
                    'rejection_reason' => $comment,
                ]);
                $this->assets->logAudit((int) $request->created_by, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'rejected', $approverId, null, $request->fresh()->toArray(), $comment);
            });
        }

        return $decided->fresh();
    }

    // ------------------------------------------------------------------
    // Start → complete → verify → cancel
    // ------------------------------------------------------------------

    public function startMaintenance(FixedAssetMaintenanceRequest $request, int $creatorId, int $userId): FixedAssetMaintenanceRequest
    {
        $this->assets->assertSameCompany($request, $creatorId);

        if ($request->status !== FixedAssetMaintenanceRequest::STATUS_APPROVED) {
            throw new FixedAssetException('Only an approved maintenance request can be started.');
        }

        return DB::transaction(function () use ($request, $creatorId, $userId) {
            $request->update([
                'status' => FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
                'started_by' => $userId,
                'started_at' => now(),
            ]);
            $this->assets->logAudit($creatorId, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'started', $userId, null, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    /**
     * Completion restores the asset to the status it had before
     * maintenance (available / assigned / ...). The assignment,
     * cost centre and depreciation schedule are untouched — the
     * restore only moves the status back.
     */
    public function completeMaintenance(FixedAssetMaintenanceRequest $request, int $creatorId, int $userId, ?string $notes = null): FixedAssetMaintenanceRequest
    {
        $this->assets->assertSameCompany($request, $creatorId);

        if ($request->status !== FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS) {
            throw new FixedAssetException('Only maintenance in progress can be completed.');
        }

        return DB::transaction(function () use ($request, $creatorId, $userId, $notes) {
            $request->update([
                'status' => FixedAssetMaintenanceRequest::STATUS_COMPLETED,
                'completed_by' => $userId,
                'completed_at' => now(),
                'completion_notes' => $notes,
            ]);

            $this->restoreAssetStatus($request, $userId, 'Maintenance completed');

            $this->assets->logAudit($creatorId, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'completed', $userId, null, $request->fresh()->toArray(), $notes);

            return $request->fresh();
        });
    }

    public function verifyMaintenance(FixedAssetMaintenanceRequest $request, int $creatorId, int $userId): FixedAssetMaintenanceRequest
    {
        $this->assets->assertSameCompany($request, $creatorId);

        if ($request->status !== FixedAssetMaintenanceRequest::STATUS_COMPLETED) {
            throw new FixedAssetException('Only completed maintenance can be verified.');
        }

        return DB::transaction(function () use ($request, $creatorId, $userId) {
            $request->update([
                'status' => FixedAssetMaintenanceRequest::STATUS_VERIFIED,
                'verified_by' => $userId,
                'verified_at' => now(),
            ]);
            $this->assets->logAudit($creatorId, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'verified', $userId, null, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    public function cancelMaintenance(FixedAssetMaintenanceRequest $request, int $creatorId, int $userId): FixedAssetMaintenanceRequest
    {
        $this->assets->assertSameCompany($request, $creatorId);

        if (! in_array($request->status, [
            FixedAssetMaintenanceRequest::STATUS_REQUESTED,
            FixedAssetMaintenanceRequest::STATUS_APPROVED,
            FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
        ], true)) {
            throw new FixedAssetException('Only a requested, approved or in-progress maintenance request can be cancelled.');
        }

        return DB::transaction(function () use ($request, $creatorId, $userId) {
            $request->update(['status' => FixedAssetMaintenanceRequest::STATUS_CANCELLED]);

            // An approved / in-progress request already moved the
            // asset to under_maintenance — give it back.
            $this->restoreAssetStatus($request, $userId, 'Maintenance cancelled');

            $this->assets->logAudit($creatorId, $request->asset_id, 'fixed_asset_maintenance_request', $request->id, 'cancelled', $userId, null, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    /**
     * Return the asset to the status it had before maintenance.
     * Only moves it when it is still under_maintenance — if
     * something else has moved it on (e.g. a depreciation run
     * flipped it to fully_depreciated), that later state wins.
     */
    protected function restoreAssetStatus(FixedAssetMaintenanceRequest $request, int $userId, string $reason): void
    {
        if ($request->previous_status === null) {
            return;
        }

        $asset = $request->asset->fresh();
        if ($asset->status !== FixedAsset::STATUS_UNDER_MAINTENANCE) {
            return;
        }

        $this->assets->recordStatusChange($asset, $asset->status, $request->previous_status, $userId, $reason);
    }

    // ------------------------------------------------------------------
    // Costs — expenses, never capitalizations
    // ------------------------------------------------------------------

    /**
     * Record an actual cost (parts / labour / other) and post it
     * immediately: Dr maintenance expense / Cr accounts payable.
     * The asset's capitalized cost, book value and depreciation
     * schedule are never touched — a maintenance cost is an
     * expense, not an addition to the asset value.
     */
    public function recordCost(FixedAssetMaintenanceRequest $request, array $data, int $creatorId, int $userId): FixedAssetMaintenanceCost
    {
        $this->assets->assertSameCompany($request, $creatorId);

        if (! in_array($request->status, [
            FixedAssetMaintenanceRequest::STATUS_APPROVED,
            FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
            FixedAssetMaintenanceRequest::STATUS_COMPLETED,
        ], true)) {
            throw new FixedAssetException('Costs can only be recorded on approved, in-progress or completed maintenance.');
        }

        $costType = (string) ($data['cost_type'] ?? FixedAssetMaintenanceCost::COST_PARTS);
        if (! in_array($costType, FixedAssetMaintenanceCost::COST_TYPES, true)) {
            throw new FixedAssetException('Invalid maintenance cost type.');
        }

        if (! isset($data['amount']) || ! is_numeric($data['amount']) || bccomp((string) $data['amount'], '0', 4) <= 0) {
            throw new FixedAssetException('Maintenance cost must be a positive amount.');
        }
        $amount = bcadd((string) $data['amount'], '0', 4);

        // A cost posts into the current fiscal year; a closed
        // year blocks it the same way capitalization and
        // depreciation posting are blocked.
        if ($this->acquisitions->isFiscalYearClosed($creatorId, (int) now()->format('Y'))) {
            throw new FixedAssetException('Fiscal year '.now()->format('Y').' is already closed. Maintenance cost posting is blocked for closed periods.');
        }

        $asset = $request->asset;
        $expenseAccountId = $this->resolveMaintenanceExpenseAccount($asset, $creatorId);
        $payableAccountId = $this->resolvePayableAccount($creatorId);

        return DB::transaction(function () use ($request, $asset, $costType, $data, $amount, $expenseAccountId, $payableAccountId, $creatorId, $userId) {
            $cost = FixedAssetMaintenanceCost::create([
                'created_by' => $creatorId,
                'maintenance_request_id' => $request->id,
                'asset_id' => $asset->id,
                'cost_type' => $costType,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'vendor' => $data['vendor'] ?? null,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            $journal = $this->poster->post(
                $creatorId,
                now()->format('Y-m-d'),
                'FA-MNT-'.$request->id.'-'.$cost->id,
                'Maintenance '.$asset->code.' ('.$costType.')',
                [
                    [
                        'account' => $expenseAccountId,
                        'debit' => (float) $amount,
                        'description' => 'Maintenance '.$asset->code.' — '.$costType,
                    ],
                    [
                        'account' => $payableAccountId,
                        'credit' => (float) $amount,
                        'description' => 'Maintenance payable '.$asset->code,
                    ],
                ]
            );

            $cost->update(['journal_id' => $journal->id]);

            FixedAssetAccountingEntry::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'transaction_type' => FixedAssetAccountingEntry::TYPE_MAINTENANCE,
                'transaction_id' => $cost->id,
                'journal_id' => $journal->id,
            ]);

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_maintenance_cost', $cost->id, 'recorded', $userId, null, $cost->fresh()->toArray());

            return $cost->fresh();
        });
    }

    /**
     * Maintenance expense account: the per-company setting
     * (default SNC 6226 "Conservation and repair"), resolved
     * exact-code-then-prefix, falling back to the class 62
     * prefix. The plan/spec names no maintenance account, so
     * this is a Phase 4 decision — the accountant can change
     * the code in Fixed Assets → Settings.
     */
    public function resolveMaintenanceExpenseAccount(FixedAsset $asset, int $creatorId): int
    {
        $code = FixedAssetSettings::maintenanceExpenseCode($creatorId);

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
        if ($id) {
            return (int) $id;
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '62');
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('No maintenance expense account is configured and no class 62 account exists. Set the maintenance expense account code in Fixed Assets → Settings (setting fixed_asset_maintenance_expense_code).');
    }

    /**
     * Maintenance is performed by an external provider and
     * invoiced, so the credit side is accounts payable 421 —
     * the payable itself is settled through the normal
     * Bill/Payment flow, not here.
     */
    public function resolvePayableAccount(int $creatorId): int
    {
        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '421');
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('No accounts payable account (421) exists for this company. Maintenance cost posting needs one.');
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    protected function requestOf(FixedAssetApproval $approval): FixedAssetMaintenanceRequest
    {
        $request = FixedAssetMaintenanceRequest::where('created_by', $approval->created_by)
            ->where('id', $approval->transaction_id)
            ->first();

        if (! $request) {
            throw new FixedAssetException('Maintenance request not found for this approval.');
        }

        return $request;
    }
}
