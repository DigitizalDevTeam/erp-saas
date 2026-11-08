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
 * Fixed Assets Phase 2 — Transfer (plan §5, §10; spec §12).
 *
 * Workflow: Request → Manager approval → Handover → Destination
 * receipt → update location + custodian history. The asset
 * master is updated ONLY on receipt — a transfer in flight
 * never moves the asset; while it is in flight the asset
 * status is "in_transfer". A rejected or cancelled transfer
 * puts the asset back to exactly the status it had before
 * (assigned / available), and its location / custodian /
 * cost centre are untouched.
 *
 * Approval reuses the Phase 0 FixedAssetApproval engine
 * (type "transfer", permission "approve fixed asset
 * transfers"); the approver must differ from the requester —
 * no self-approval. One approval level in Phase 2 (spec §12
 * is a manager approval; per-company multi-level transfer
 * approvals are a later decision, like acquisition levels).
 *
 * A transfer is not a disposal: it never touches the
 * depreciation schedule and posts no journal (plan §7 has no
 * transfer posting). Cost-centre assignment follows the asset
 * on receipt (plan §12c) — the transfer row keeps the
 * historical assignment, effective from the receipt date.
 */
class FixedAssetTransferService
{
    public function __construct(
        protected FixedAssetService $assets,
    ) {
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * An asset can be transferred only while it is available or
     * assigned. Disposed / written off / lost / stolen / damaged
     * assets can never be transferred again — service-layer
     * guard, not only a UI rule.
     */
    public function assertTransferable(FixedAsset $asset, int $creatorId): void
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if (! in_array($asset->status, [FixedAsset::STATUS_AVAILABLE, FixedAsset::STATUS_ASSIGNED], true)) {
            if (in_array($asset->status, [FixedAsset::STATUS_DISPOSED, FixedAsset::STATUS_WRITTEN_OFF, FixedAsset::STATUS_LOST, FixedAsset::STATUS_STOLEN, FixedAsset::STATUS_DAMAGED], true)) {
                throw new FixedAssetException("A {$asset->status} asset can never be transferred again.");
            }

            throw new FixedAssetException("Only an available or assigned asset can be transferred (asset is {$asset->status}).");
        }

        if ($this->activeTransferFor($asset, $creatorId)) {
            throw new FixedAssetException('This asset already has an active transfer.');
        }

        if (Schema::hasTable('fixed_asset_assignments')
            && FixedAssetAssignment::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                // An ACCEPTED assignment does not block a
                // transfer: the transfer supersedes it, and the
                // assignment is closed on receipt (spec §12:
                // "previous assignment stays in history"). Only
                // an in-flight assignment blocks a transfer.
                ->whereIn('status', [FixedAssetAssignment::STATUS_REQUESTED, FixedAssetAssignment::STATUS_APPROVED, FixedAssetAssignment::STATUS_HANDED_OVER])
                ->exists()
        ) {
            throw new FixedAssetException('This asset already has an active assignment.');
        }
    }

    public function activeTransferFor(FixedAsset $asset, int $creatorId): ?FixedAssetTransfer
    {
        return FixedAssetTransfer::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->whereIn('status', FixedAssetTransfer::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Request + approval
    // ------------------------------------------------------------------

    public function requestTransfer(FixedAsset $asset, array $data, int $creatorId, int $userId): FixedAssetTransfer
    {
        $this->assertTransferable($asset, $creatorId);

        $toLocation = $this->resolveLocation($data['to_location_id'] ?? null, $creatorId);
        $toCustodian = $this->resolveCustodian($data['to_custodian_id'] ?? null, $creatorId);
        $toCostCentre = $this->resolveCostCentre($data['to_cost_centre_id'] ?? null, $creatorId);

        // A transfer must actually move something.
        $movesLocation = $toLocation && (int) $toLocation->id !== (int) $asset->location_id;
        $movesCustodian = $toCustodian && (int) $toCustodian->id !== (int) $asset->custodian_id;
        $movesCostCentre = $toCostCentre && (int) $toCostCentre->id !== (int) $asset->cost_centre_id;
        if (! $movesLocation && ! $movesCustodian && ! $movesCostCentre) {
            throw new FixedAssetException('A transfer must change the location, the custodian or the cost centre.');
        }

        return DB::transaction(function () use ($asset, $toLocation, $toCustodian, $toCostCentre, $data, $creatorId, $userId) {
            $transfer = FixedAssetTransfer::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'from_location_id' => $asset->location_id,
                'to_location_id' => $toLocation?->id,
                'from_custodian_id' => $asset->custodian_id,
                'to_custodian_id' => $toCustodian?->id,
                'from_cost_centre_id' => $asset->cost_centre_id,
                'to_cost_centre_id' => $toCostCentre?->id,
                'status' => FixedAssetTransfer::STATUS_REQUESTED,
                'previous_status' => $asset->status,
                'requested_by' => $userId,
                'requested_at' => now(),
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // One manager-approval level, a different user than
            // the requester (enforced by decideApproval).
            $this->assets->requestApproval(
                $asset,
                FixedAssetApproval::TYPE_TRANSFER,
                $transfer->id,
                $userId,
                (string) $asset->amount_base,
                1
            );

            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_IN_TRANSFER, $userId, 'Transfer requested');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_transfer', $transfer->id, 'requested', $userId, null, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    /**
     * Decide the transfer approval. Wraps the Phase 0 decision
     * (which already refuses self-approval). Approved → the
     * transfer can be handed over. Rejected → the transfer is
     * rejected and the asset goes back to its previous status.
     * Returned → the transfer stays requested (the approver's
     * comment is on the approval row).
     */
    public function decideTransferApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $transfer = $this->transferOf($approval);
        $this->assets->assertSameCompany($transfer, (int) $approval->created_by);

        if ($approval->transaction_type !== FixedAssetApproval::TYPE_TRANSFER) {
            throw new FixedAssetException('This approval is not a transfer approval.');
        }

        $decided = $this->assets->decideApproval($approval, $approverId, $decision, $comment);

        if ($decision === FixedAssetApproval::DECISION_APPROVED) {
            DB::transaction(function () use ($transfer, $approverId) {
                $transfer->update([
                    'status' => FixedAssetTransfer::STATUS_APPROVED,
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                ]);
                $this->assets->logAudit((int) $transfer->created_by, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'approved', $approverId, null, $transfer->fresh()->toArray());
            });
        }

        if ($decision === FixedAssetApproval::DECISION_REJECTED) {
            DB::transaction(function () use ($transfer, $approverId) {
                $transfer->update(['status' => FixedAssetTransfer::STATUS_REJECTED]);
                $asset = $transfer->asset;
                $this->assets->recordStatusChange($asset, $asset->status, $transfer->previous_status ?? FixedAsset::STATUS_AVAILABLE, $approverId, 'Transfer rejected');
                $this->assets->logAudit((int) $transfer->created_by, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'rejected', $approverId, null, $transfer->fresh()->toArray());
            });
        }

        return $decided->fresh();
    }

    // ------------------------------------------------------------------
    // Handover → receipt / rejection / cancellation
    // ------------------------------------------------------------------

    public function handOverTransfer(FixedAssetTransfer $transfer, int $creatorId, int $userId): FixedAssetTransfer
    {
        $this->assets->assertSameCompany($transfer, $creatorId);

        if ($transfer->status !== FixedAssetTransfer::STATUS_APPROVED) {
            throw new FixedAssetException('Only an approved transfer can be handed over.');
        }

        return DB::transaction(function () use ($transfer, $creatorId, $userId) {
            $transfer->update([
                'status' => FixedAssetTransfer::STATUS_HANDED_OVER,
                'handed_over_by' => $userId,
                'handed_over_at' => now(),
            ]);
            $this->assets->logAudit($creatorId, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'handed_over', $userId, null, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    /**
     * Destination receipt — the only step that moves the asset.
     * Location / custodian / cost centre are updated from the
     * transfer's "to" values; a null "to" value keeps the
     * asset's current value. The depreciation schedule is
     * untouched and no journal is posted.
     */
    public function receiveTransfer(FixedAssetTransfer $transfer, int $creatorId, int $userId): FixedAssetTransfer
    {
        $this->assets->assertSameCompany($transfer, $creatorId);

        if ($transfer->status !== FixedAssetTransfer::STATUS_HANDED_OVER) {
            throw new FixedAssetException('Only a handed-over transfer can be received.');
        }

        $asset = $transfer->asset;

        return DB::transaction(function () use ($transfer, $asset, $creatorId, $userId) {
            $transfer->update([
                'status' => FixedAssetTransfer::STATUS_RECEIVED,
                'received_by' => $userId,
                'received_at' => now(),
                'effective_date' => now()->toDateString(),
            ]);

            $newCustodianId = $transfer->to_custodian_id ?? $asset->custodian_id;
            $asset->update([
                'location_id' => $transfer->to_location_id ?? $asset->location_id,
                'custodian_id' => $newCustodianId,
                'cost_centre_id' => $transfer->to_cost_centre_id ?? $asset->cost_centre_id,
            ]);

            // The transfer supersedes the previous assignment:
            // close it (history kept) instead of leaving two
            // "active" holders of the same asset.
            if (Schema::hasTable('fixed_asset_assignments')) {
                FixedAssetAssignment::where('created_by', $creatorId)
                    ->where('asset_id', $asset->id)
                    ->where('status', FixedAssetAssignment::STATUS_ACCEPTED)
                    ->update([
                        'status' => FixedAssetAssignment::STATUS_RETURNED,
                        'returned_at' => now(),
                        'effective_to' => now()->toDateString(),
                        'return_reason' => 'Transferred',
                    ]);
            }

            $newStatus = $newCustodianId ? FixedAsset::STATUS_ASSIGNED : FixedAsset::STATUS_AVAILABLE;
            $this->assets->recordStatusChange($asset, $asset->status, $newStatus, $userId, 'Transfer received');

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_transfer', $transfer->id, 'received', $userId, null, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    /**
     * Reject before handover — the asset goes back to its
     * previous status; nothing moves. After handover the only
     * way forward is receipt.
     */
    public function rejectTransfer(FixedAssetTransfer $transfer, int $creatorId, int $userId, ?string $reason = null): FixedAssetTransfer
    {
        $this->assets->assertSameCompany($transfer, $creatorId);

        if (! in_array($transfer->status, [FixedAssetTransfer::STATUS_REQUESTED, FixedAssetTransfer::STATUS_APPROVED], true)) {
            throw new FixedAssetException('Only a requested or approved transfer can be rejected (a handed-over transfer must be received).');
        }

        return DB::transaction(function () use ($transfer, $creatorId, $userId, $reason) {
            $transfer->update(['status' => FixedAssetTransfer::STATUS_REJECTED]);
            $asset = $transfer->asset;
            $this->assets->recordStatusChange($asset, $asset->status, $transfer->previous_status ?? FixedAsset::STATUS_AVAILABLE, $userId, 'Transfer rejected');
            $this->assets->logAudit($creatorId, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'rejected', $userId, null, $transfer->fresh()->toArray(), $reason);

            return $transfer->fresh();
        });
    }

    public function cancelTransfer(FixedAssetTransfer $transfer, int $creatorId, int $userId): FixedAssetTransfer
    {
        $this->assets->assertSameCompany($transfer, $creatorId);

        if (! in_array($transfer->status, [FixedAssetTransfer::STATUS_REQUESTED, FixedAssetTransfer::STATUS_APPROVED], true)) {
            throw new FixedAssetException('Only a requested or approved transfer can be cancelled (a handed-over transfer must be received).');
        }

        return DB::transaction(function () use ($transfer, $creatorId, $userId) {
            $transfer->update(['status' => FixedAssetTransfer::STATUS_CANCELLED]);
            $asset = $transfer->asset;
            $this->assets->recordStatusChange($asset, $asset->status, $transfer->previous_status ?? FixedAsset::STATUS_AVAILABLE, $userId, 'Transfer cancelled');
            $this->assets->logAudit($creatorId, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'cancelled', $userId, null, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    /**
     * Phase 6 — cancel an in-flight transfer because the asset is
     * being disposed. Unlike cancelTransfer(), this NEVER touches
     * the asset status: the disposal owns the asset's status from
     * here (it flips to disposed in the same transaction), and a
     * status restore would write a bogus "disposal_pending →
     * available" change into the timeline. It also covers a
     * handed-over transfer, which cancelTransfer() refuses — a
     * handed-over transfer can never be received once the asset
     * is disposed, so leaving it open would strand it forever.
     */
    public function cancelTransferForDisposal(FixedAssetTransfer $transfer, int $creatorId, int $userId): FixedAssetTransfer
    {
        $this->assets->assertSameCompany($transfer, $creatorId);

        if (! in_array($transfer->status, [
            FixedAssetTransfer::STATUS_REQUESTED,
            FixedAssetTransfer::STATUS_APPROVED,
            FixedAssetTransfer::STATUS_HANDED_OVER,
        ], true)) {
            throw new FixedAssetException('Only a requested, approved or handed-over transfer can be cancelled for disposal.');
        }

        return DB::transaction(function () use ($transfer, $creatorId, $userId) {
            $transfer->update(['status' => FixedAssetTransfer::STATUS_CANCELLED]);
            $this->assets->logAudit($creatorId, $transfer->asset_id, 'fixed_asset_transfer', $transfer->id, 'cancelled', $userId, null, $transfer->fresh()->toArray(), 'Cancelled — asset disposed');

            return $transfer->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    protected function transferOf(FixedAssetApproval $approval): FixedAssetTransfer
    {
        $transfer = FixedAssetTransfer::where('created_by', $approval->created_by)
            ->where('id', $approval->transaction_id)
            ->first();

        if (! $transfer) {
            throw new FixedAssetException('Transfer not found for this approval.');
        }

        return $transfer;
    }

    protected function resolveCustodian(mixed $custodianId, int $creatorId): ?Employee
    {
        if ($custodianId === null || $custodianId === '') {
            return null;
        }

        $custodian = Employee::where('created_by', $creatorId)->where('id', (int) $custodianId)->first();
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
