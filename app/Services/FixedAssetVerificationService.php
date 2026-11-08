<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetPhysicalVerification;
use App\Models\FixedAssetVerificationItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 4 — Physical verification / stocktake
 * (plan §5, §10; spec §19).
 *
 * Workflow: planned → in_progress → closed; cancelled is
 * terminal. A campaign covers one location, or the whole
 * company when location_id is null. Expected assets are
 * snapshotted into verification items at creation, with the
 * asset's status at that time (previous_status).
 *
 * While the campaign runs its assets are under_verification.
 * On close each asset returns to its previous status — except
 * missing ones (flagged "lost") and damaged ones (flagged
 * "damaged"). Missing assets are never automatically written
 * off: a write-off is a Phase 5 disposal decision, and the
 * asset stays on the books (and keeps depreciating) until
 * someone acts on it there. A wrong location / wrong
 * custodian never rewrites the asset master — the item
 * records where the asset was actually found, and a
 * transfer / assignment fixes it afterwards.
 *
 * A campaign can only close once every item has been checked.
 */
class FixedAssetVerificationService
{
    public function __construct(
        protected FixedAssetService $assets,
    ) {
    }

    /** Asset statuses that belong in a stocktake snapshot. */
    public const VERIFIABLE_STATUSES = [
        FixedAsset::STATUS_AVAILABLE,
        FixedAsset::STATUS_ACTIVE,
        FixedAsset::STATUS_ASSIGNED,
        FixedAsset::STATUS_FULLY_DEPRECIATED,
        FixedAsset::STATUS_OUT_OF_SERVICE,
        FixedAsset::STATUS_IMPAIRED,
        FixedAsset::STATUS_UNDER_MAINTENANCE,
    ];

    // ------------------------------------------------------------------
    // Create + start
    // ------------------------------------------------------------------

    public function createCampaign(array $data, int $creatorId, int $userId): FixedAssetPhysicalVerification
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new FixedAssetException('A verification campaign needs a name.');
        }

        if (empty($data['deadline'])) {
            throw new FixedAssetException('A verification campaign needs a deadline.');
        }
        try {
            $deadline = Carbon::parse($data['deadline'])->toDateString();
        } catch (\Exception) {
            throw new FixedAssetException('Invalid verification deadline.');
        }

        $location = null;
        if (! empty($data['location_id'])) {
            $location = FixedAssetLocation::where('created_by', $creatorId)->where('id', (int) $data['location_id'])->first();
            if (! $location) {
                throw new FixedAssetException('Location not found for this company.');
            }
        }

        $assets = FixedAsset::where('created_by', $creatorId)
            ->whereIn('status', self::VERIFIABLE_STATUSES)
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->orderBy('code')
            ->get();

        if ($assets->isEmpty()) {
            throw new FixedAssetException('There are no assets to verify'.($location ? ' at this location.' : '.'));
        }

        return DB::transaction(function () use ($data, $name, $deadline, $location, $assets, $creatorId, $userId) {
            $verification = FixedAssetPhysicalVerification::create([
                'created_by' => $creatorId,
                'location_id' => $location?->id,
                'name' => $name,
                'deadline' => $deadline,
                'status' => FixedAssetPhysicalVerification::STATUS_PLANNED,
                'created_by_user' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($assets as $asset) {
                FixedAssetVerificationItem::create([
                    'created_by' => $creatorId,
                    'verification_id' => $verification->id,
                    'asset_id' => $asset->id,
                    'expected_location_id' => $asset->location_id,
                    'expected_custodian_id' => $asset->custodian_id,
                    'previous_status' => $asset->status,
                    'result' => FixedAssetVerificationItem::RESULT_PENDING,
                ]);
            }

            $this->assets->logAudit($creatorId, null, 'fixed_asset_physical_verification', $verification->id, 'created', $userId, null, $verification->fresh()->toArray());

            return $verification->fresh();
        });
    }

    /**
     * Start the campaign: every snapshotted asset that is still
     * in its snapshot status moves to under_verification. An
     * asset that has legitimately moved on since the snapshot
     * (e.g. it went into maintenance) is left alone — its item
     * is still checkable.
     */
    public function startCampaign(FixedAssetPhysicalVerification $verification, int $creatorId, int $userId): FixedAssetPhysicalVerification
    {
        $this->assets->assertSameCompany($verification, $creatorId);

        if ($verification->status !== FixedAssetPhysicalVerification::STATUS_PLANNED) {
            throw new FixedAssetException('Only a planned verification campaign can be started.');
        }

        return DB::transaction(function () use ($verification, $creatorId, $userId) {
            $verification->update([
                'status' => FixedAssetPhysicalVerification::STATUS_IN_PROGRESS,
                'started_by' => $userId,
                'started_at' => now(),
            ]);

            foreach ($verification->items as $item) {
                $asset = $item->asset;
                if (! $asset) {
                    continue;
                }
                $asset = $asset->fresh();
                if ($asset->status === $item->previous_status) {
                    $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_UNDER_VERIFICATION, $userId, 'Physical verification started ('.$verification->name.')');
                }
            }

            $this->assets->logAudit($creatorId, null, 'fixed_asset_physical_verification', $verification->id, 'started', $userId, null, $verification->fresh()->toArray());

            return $verification->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Check an item
    // ------------------------------------------------------------------

    public function checkItem(FixedAssetVerificationItem $item, string $result, int $creatorId, int $userId, ?string $note = null): FixedAssetVerificationItem
    {
        $this->assets->assertSameCompany($item, $creatorId);

        if (! in_array($result, FixedAssetVerificationItem::RESULTS, true) || $result === FixedAssetVerificationItem::RESULT_PENDING) {
            throw new FixedAssetException('Invalid verification result.');
        }

        $verification = $item->verification;
        if ($verification->status !== FixedAssetPhysicalVerification::STATUS_IN_PROGRESS) {
            throw new FixedAssetException('Assets can only be checked while the verification campaign is running.');
        }

        return DB::transaction(function () use ($item, $result, $creatorId, $userId, $note, $verification) {
            $item->update([
                'result' => $result,
                'checked_by' => $userId,
                'checked_at' => now(),
                'note' => $note,
            ]);

            $this->assets->logAudit($creatorId, $item->asset_id, 'fixed_asset_verification_item', $item->id, 'checked:'.$result, $userId, null, $item->fresh()->toArray(), $note);

            return $item->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Close / cancel — restore statuses
    // ------------------------------------------------------------------

    /**
     * Close the campaign. Every item must have been checked
     * first. Each asset returns to its previous status — except
     * missing ones, which are flagged "lost", and damaged ones,
     * which are flagged "damaged". Neither is a write-off: the
     * asset stays on the books and keeps depreciating until
     * Phase 5 acts on it.
     */
    public function closeCampaign(FixedAssetPhysicalVerification $verification, int $creatorId, int $userId): FixedAssetPhysicalVerification
    {
        $this->assets->assertSameCompany($verification, $creatorId);

        if ($verification->status !== FixedAssetPhysicalVerification::STATUS_IN_PROGRESS) {
            throw new FixedAssetException('Only a running verification campaign can be closed.');
        }

        $unchecked = $verification->items()->where('result', FixedAssetVerificationItem::RESULT_PENDING)->count();
        if ($unchecked > 0) {
            throw new FixedAssetException($unchecked.' asset(s) have not been checked yet. Check every asset before closing the campaign.');
        }

        return DB::transaction(function () use ($verification, $creatorId, $userId) {
            foreach ($verification->items()->with('asset')->get() as $item) {
                $asset = $item->asset;
                if (! $asset) {
                    continue;
                }
                $asset = $asset->fresh();

                // Only move an asset that is still under
                // verification — if something else has moved it on
                // (e.g. a depreciation run flipped it to fully
                // depreciated), that later state wins.
                if ($asset->status !== FixedAsset::STATUS_UNDER_VERIFICATION) {
                    continue;
                }

                $target = $item->previous_status ?? FixedAsset::STATUS_AVAILABLE;
                if ($item->result === FixedAssetVerificationItem::RESULT_MISSING) {
                    $target = FixedAsset::STATUS_LOST;
                } elseif ($item->result === FixedAssetVerificationItem::RESULT_DAMAGED) {
                    $target = FixedAsset::STATUS_DAMAGED;
                }

                $this->assets->recordStatusChange($asset, $asset->status, $target, $userId, 'Physical verification closed ('.$verification->name.'): '.$item->result);
            }

            $verification->update([
                'status' => FixedAssetPhysicalVerification::STATUS_CLOSED,
                'closed_by' => $userId,
                'closed_at' => now(),
            ]);

            $this->assets->logAudit($creatorId, null, 'fixed_asset_physical_verification', $verification->id, 'closed', $userId, null, $verification->fresh()->toArray());

            return $verification->fresh();
        });
    }

    public function cancelCampaign(FixedAssetPhysicalVerification $verification, int $creatorId, int $userId): FixedAssetPhysicalVerification
    {
        $this->assets->assertSameCompany($verification, $creatorId);

        if (! in_array($verification->status, [
            FixedAssetPhysicalVerification::STATUS_PLANNED,
            FixedAssetPhysicalVerification::STATUS_IN_PROGRESS,
        ], true)) {
            throw new FixedAssetException('Only a planned or running verification campaign can be cancelled.');
        }

        return DB::transaction(function () use ($verification, $creatorId, $userId) {
            // Assets already moved to under_verification go back
            // to their snapshot status.
            foreach ($verification->items()->with('asset')->get() as $item) {
                $asset = $item->asset;
                if (! $asset) {
                    continue;
                }
                $asset = $asset->fresh();
                if ($asset->status === FixedAsset::STATUS_UNDER_VERIFICATION && $item->previous_status) {
                    $this->assets->recordStatusChange($asset, $asset->status, $item->previous_status, $userId, 'Physical verification cancelled ('.$verification->name.')');
                }
            }

            $verification->update(['status' => FixedAssetPhysicalVerification::STATUS_CANCELLED]);

            $this->assets->logAudit($creatorId, null, 'fixed_asset_physical_verification', $verification->id, 'cancelled', $userId, null, $verification->fresh()->toArray());

            return $verification->fresh();
        });
    }
}
