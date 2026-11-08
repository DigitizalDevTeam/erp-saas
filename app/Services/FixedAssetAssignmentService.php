<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 2 — Assignment (plan §5, §10; spec §11).
 *
 * Workflow: AVAILABLE → ASSIGNMENT REQUESTED → APPROVED →
 * HANDED OVER → ACCEPTED BY CUSTODIAN. The asset is not
 * "assigned" until the custodian accepts; a refused assignment
 * returns it to available with the refusal recorded. Only one
 * active assignment per asset; history is preserved — every
 * assignment/return is its own row, never overwritten.
 *
 * The asset status stays "available" through the pipeline and
 * becomes "assigned" only on acceptance (and back to
 * "available" on return). Approval reuses the Phase 0
 * FixedAssetApproval engine (new type "assignment"); the
 * approver must differ from the requester — no self-approval,
 * at any value.
 *
 * Custodian acceptance is recorded with the acting user id.
 * The controller lets the custodian's linked user (Employee
 * user_id) or a Fixed Asset Officer (manage fixed assets)
 * record it; the service itself only records who acted.
 *
 * Effective-dated cost-centre assignment (plan §12c):
 * effective_from is the acceptance date, effective_to the
 * return date. One cost centre per assignment (no splits in
 * Phase 2 — splits are a Cost Centre module concern).
 */
class FixedAssetAssignmentService
{
    public function __construct(
        protected FixedAssetService $assets,
    ) {
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * An asset can be assigned only while it is available
     * (capitalized and not in use). Disposed / written off /
     * lost / stolen / damaged assets can never be assigned
     * again — this is a service-layer guard, not only a UI rule.
     */
    public function assertAssignable(FixedAsset $asset, int $creatorId): void
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if ($asset->status !== FixedAsset::STATUS_AVAILABLE) {
            if (in_array($asset->status, [FixedAsset::STATUS_DISPOSED, FixedAsset::STATUS_WRITTEN_OFF, FixedAsset::STATUS_LOST, FixedAsset::STATUS_STOLEN, FixedAsset::STATUS_DAMAGED], true)) {
                throw new FixedAssetException("A {$asset->status} asset can never be assigned again.");
            }

            throw new FixedAssetException("Only an available asset can be assigned (asset is {$asset->status}).");
        }

        if ($this->activeAssignmentFor($asset, $creatorId)) {
            throw new FixedAssetException('This asset already has an active assignment.');
        }

        if (Schema::hasTable('fixed_asset_transfers')
            && FixedAssetTransfer::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', FixedAssetTransfer::ACTIVE_STATUSES)
                ->exists()
        ) {
            throw new FixedAssetException('This asset already has an active transfer.');
        }
    }

    public function activeAssignmentFor(FixedAsset $asset, int $creatorId): ?FixedAssetAssignment
    {
        return FixedAssetAssignment::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->whereIn('status', FixedAssetAssignment::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Request + approval
    // ------------------------------------------------------------------

    public function requestAssignment(FixedAsset $asset, array $data, int $creatorId, int $userId): FixedAssetAssignment
    {
        $this->assertAssignable($asset, $creatorId);

        $custodian = $this->resolveCustodian((int) ($data['custodian_id'] ?? 0), $creatorId);
        $location = $this->resolveLocation($data['location_id'] ?? null, $creatorId);
        $costCentre = $this->resolveCostCentre($data['cost_centre_id'] ?? null, $creatorId);

        return DB::transaction(function () use ($asset, $custodian, $location, $costCentre, $data, $creatorId, $userId) {
            $assignment = FixedAssetAssignment::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'custodian_id' => $custodian->id,
                'location_id' => $location?->id,
                'cost_centre_id' => $costCentre?->id,
                'previous_custodian_id' => $asset->custodian_id,
                'previous_location_id' => $asset->location_id,
                'previous_cost_centre_id' => $asset->cost_centre_id,
                'status' => FixedAssetAssignment::STATUS_REQUESTED,
                'condition' => $data['condition'] ?? null,
                'accessories' => $data['accessories'] ?? null,
                'requested_by' => $userId,
                'requested_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // One approval level, a different user than the
            // requester (enforced by decideApproval).
            $this->assets->requestApproval(
                $asset,
                FixedAssetApproval::TYPE_ASSIGNMENT,
                $assignment->id,
                $userId,
                (string) $asset->amount_base,
                1
            );

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_assignment', $assignment->id, 'requested', $userId, null, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    /**
     * Decide the assignment approval. Wraps the Phase 0 decision
     * (which already refuses self-approval). Approved → the
     * assignment can be handed over. Rejected → the assignment
     * is cancelled. Returned → the assignment stays requested
     * (the approver's comment is on the approval row).
     */
    public function decideAssignmentApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $assignment = $this->assignmentOf($approval);
        $this->assets->assertSameCompany($assignment, (int) $approval->created_by);

        if ($approval->transaction_type !== FixedAssetApproval::TYPE_ASSIGNMENT) {
            throw new FixedAssetException('This approval is not an assignment approval.');
        }

        $decided = $this->assets->decideApproval($approval, $approverId, $decision, $comment);

        if ($decision === FixedAssetApproval::DECISION_APPROVED) {
            DB::transaction(function () use ($assignment, $approverId) {
                $assignment->update([
                    'status' => FixedAssetAssignment::STATUS_APPROVED,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                ]);
                $this->assets->logAudit((int) $assignment->created_by, $assignment->asset_id, 'fixed_asset_assignment', $assignment->id, 'approved', $approverId, null, $assignment->fresh()->toArray());
            });
        }

        if ($decision === FixedAssetApproval::DECISION_REJECTED) {
            DB::transaction(function () use ($assignment, $approverId) {
                $assignment->update(['status' => FixedAssetAssignment::STATUS_CANCELLED]);
                $this->assets->logAudit((int) $assignment->created_by, $assignment->asset_id, 'fixed_asset_assignment', $assignment->id, 'cancelled:approval_rejected', $approverId, null, $assignment->fresh()->toArray());
            });
        }

        return $decided->fresh();
    }

    // ------------------------------------------------------------------
    // Handover → acceptance / refusal → return
    // ------------------------------------------------------------------

    public function handOverAssignment(FixedAssetAssignment $assignment, int $creatorId, int $userId): FixedAssetAssignment
    {
        $this->assets->assertSameCompany($assignment, $creatorId);

        if ($assignment->status !== FixedAssetAssignment::STATUS_APPROVED) {
            throw new FixedAssetException('Only an approved assignment can be handed over.');
        }

        return DB::transaction(function () use ($assignment, $creatorId, $userId) {
            $assignment->update([
                'status' => FixedAssetAssignment::STATUS_HANDED_OVER,
                'handed_over_by' => $userId,
                'handed_over_at' => now(),
            ]);
            $this->assets->logAudit($creatorId, $assignment->asset_id, 'fixed_asset_assignment', $assignment->id, 'handed_over', $userId, null, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    /**
     * Custodian acceptance — the only step that makes the asset
     * "assigned". Location / cost centre move with the asset;
     * a null value on the assignment keeps the asset's current
     * value (the assignment form only sends what changes).
     */
    public function acceptAssignment(FixedAssetAssignment $assignment, int $creatorId, int $userId): FixedAssetAssignment
    {
        $this->assets->assertSameCompany($assignment, $creatorId);

        if ($assignment->status !== FixedAssetAssignment::STATUS_HANDED_OVER) {
            throw new FixedAssetException('Only a handed-over assignment can be accepted.');
        }

        $asset = $assignment->asset;

        // The assignment itself is the active one — the guard
        // here is only that the asset is still available and no
        // transfer is in flight for it.
        if ($asset->status !== FixedAsset::STATUS_AVAILABLE) {
            throw new FixedAssetException("Only an available asset can be assigned (asset is {$asset->status}).");
        }
        if (Schema::hasTable('fixed_asset_transfers')
            && FixedAssetTransfer::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', FixedAssetTransfer::ACTIVE_STATUSES)
                ->exists()
        ) {
            throw new FixedAssetException('This asset already has an active transfer.');
        }

        return DB::transaction(function () use ($assignment, $asset, $creatorId, $userId) {
            $assignment->update([
                'status' => FixedAssetAssignment::STATUS_ACCEPTED,
                'accepted_by' => $userId,
                'accepted_at' => now(),
                'effective_from' => now()->toDateString(),
            ]);

            $asset->update([
                'custodian_id' => $assignment->custodian_id,
                'location_id' => $assignment->location_id ?? $asset->location_id,
                'cost_centre_id' => $assignment->cost_centre_id ?? $asset->cost_centre_id,
            ]);
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_ASSIGNED, $userId, 'Assignment accepted by custodian');

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_assignment', $assignment->id, 'accepted', $userId, null, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    /**
     * Custodian refusal — the asset was never assigned, so it
     * stays available; the refusal and its reason are recorded
     * on the assignment row.
     */
    public function refuseAssignment(FixedAssetAssignment $assignment, int $creatorId, int $userId, ?string $reason = null): FixedAssetAssignment
    {
        $this->assets->assertSameCompany($assignment, $creatorId);

        if ($assignment->status !== FixedAssetAssignment::STATUS_HANDED_OVER) {
            throw new FixedAssetException('Only a handed-over assignment can be refused.');
        }

        return DB::transaction(function () use ($assignment, $creatorId, $userId, $reason) {
            $assignment->update([
                'status' => FixedAssetAssignment::STATUS_REFUSED,
                'refused_at' => now(),
                'refusal_reason' => $reason,
            ]);
            $this->assets->logAudit($creatorId, $assignment->asset_id, 'fixed_asset_assignment', $assignment->id, 'refused', $userId, null, $assignment->fresh()->toArray(), $reason);

            return $assignment->fresh();
        });
    }

    /**
     * Return of an accepted assignment — the asset becomes
     * available again and the custodian is cleared; the
     * location stays where the asset is. The assignment row
     * keeps its full history.
     */
    public function returnAssignment(FixedAssetAssignment $assignment, int $creatorId, int $userId, ?string $reason = null): FixedAssetAssignment
    {
        $this->assets->assertSameCompany($assignment, $creatorId);

        if ($assignment->status !== FixedAssetAssignment::STATUS_ACCEPTED) {
            throw new FixedAssetException('Only an accepted assignment can be returned.');
        }

        $asset = $assignment->asset;

        return DB::transaction(function () use ($assignment, $asset, $creatorId, $userId, $reason) {
            $assignment->update([
                'status' => FixedAssetAssignment::STATUS_RETURNED,
                'returned_at' => now(),
                'effective_to' => now()->toDateString(),
                'return_reason' => $reason,
            ]);

            $asset->update(['custodian_id' => null]);
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_AVAILABLE, $userId, 'Assignment returned');

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_assignment', $assignment->id, 'returned', $userId, null, $assignment->fresh()->toArray(), $reason);

            return $assignment->fresh();
        });
    }

    public function cancelAssignment(FixedAssetAssignment $assignment, int $creatorId, int $userId): FixedAssetAssignment
    {
        $this->assets->assertSameCompany($assignment, $creatorId);

        if (! in_array($assignment->status, [FixedAssetAssignment::STATUS_REQUESTED, FixedAssetAssignment::STATUS_APPROVED], true)) {
            throw new FixedAssetException('Only a requested or approved assignment can be cancelled (a handed-over assignment must be refused, an accepted one returned).');
        }

        return DB::transaction(function () use ($assignment, $creatorId, $userId) {
            $assignment->update(['status' => FixedAssetAssignment::STATUS_CANCELLED]);
            $this->assets->logAudit($creatorId, $assignment->asset_id, 'fixed_asset_assignment', $assignment->id, 'cancelled', $userId, null, $assignment->fresh()->toArray());

            return $assignment->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    protected function assignmentOf(FixedAssetApproval $approval): FixedAssetAssignment
    {
        $assignment = FixedAssetAssignment::where('created_by', $approval->created_by)
            ->where('id', $approval->transaction_id)
            ->first();

        if (! $assignment) {
            throw new FixedAssetException('Assignment not found for this approval.');
        }

        return $assignment;
    }

    protected function resolveCustodian(int $custodianId, int $creatorId): Employee
    {
        if ($custodianId <= 0) {
            throw new FixedAssetException('A custodian is required for an assignment.');
        }

        $custodian = Employee::where('created_by', $creatorId)->where('id', $custodianId)->first();
        if (! $custodian) {
            throw new FixedAssetException('Custodian not found for this company.');
        }

        return $custodian;
    }

    protected function resolveLocation(mixed $locationId, int $creatorId): ?FixedAssetLocation
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }

        $location = FixedAssetLocation::where('created_by', $creatorId)->where('id', (int) $locationId)->first();
        if (! $location) {
            throw new FixedAssetException('Location not found for this company.');
        }

        return $location;
    }

    protected function resolveCostCentre(mixed $costCentreId, int $creatorId): ?CostCentre
    {
        if ($costCentreId === null || $costCentreId === '') {
            return null;
        }

        $centre = CostCentre::where('created_by', $creatorId)->where('id', (int) $costCentreId)->first();
        if (! $centre) {
            throw new FixedAssetException('Cost centre not found for this company.');
        }

        return $centre;
    }
}
