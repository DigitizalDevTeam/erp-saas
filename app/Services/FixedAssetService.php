<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAuditEvent;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 0 — foundation service (plan §5, §10).
 *
 * Owns: category + location master data, approval decisions
 * (with the no-self-approval rule), status history and audit
 * events. No journals are posted here — the capitalization
 * journal is Phase 1 and goes through JournalEntryPoster only.
 *
 * All writes are company-scoped via creatorId(): every method
 * asserts the row belongs to the caller's company before it
 * touches it (AC11 pattern from Contract Management).
 */
class FixedAssetService
{
    public function assertSameCompany(object $model, int $creatorId): void
    {
        if ((int) $model->created_by !== $creatorId) {
            throw new FixedAssetException('Record not found for this company.');
        }
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function createCategory(array $data, int $creatorId, int $userId): FixedAssetCategory
    {
        $data = $this->normalizeCategoryData($data, $creatorId);

        return DB::transaction(function () use ($data, $creatorId, $userId) {
            $category = FixedAssetCategory::create($data + [
                'created_by' => $creatorId,
                'company_id' => $creatorId,
            ]);

            $this->logAudit($creatorId, null, 'fixed_asset_category', $category->id, 'created', $userId, null, $category->fresh()->toArray());

            return $category->fresh();
        });
    }

    public function updateCategory(FixedAssetCategory $category, array $data, int $creatorId, int $userId): FixedAssetCategory
    {
        $this->assertSameCompany($category, $creatorId);
        $data = $this->normalizeCategoryData($data, $creatorId, $category);

        return DB::transaction(function () use ($category, $data, $creatorId, $userId) {
            $before = $category->fresh()->toArray();
            $category->update($data);
            $this->logAudit($creatorId, null, 'fixed_asset_category', $category->id, 'updated', $userId, $before, $category->fresh()->toArray());

            return $category->fresh();
        });
    }

    public function deactivateCategory(FixedAssetCategory $category, int $creatorId, int $userId): FixedAssetCategory
    {
        $this->assertSameCompany($category, $creatorId);

        return DB::transaction(function () use ($category, $creatorId, $userId) {
            $before = $category->fresh()->toArray();
            // Soft delete: the code stays burned (the unique key
            // covers soft-deleted rows), so it is never reused.
            $category->delete();
            $this->logAudit($creatorId, null, 'fixed_asset_category', $category->id, 'deactivated', $userId, $before, null);

            return $category->fresh();
        });
    }

    protected function normalizeCategoryData(array $data, int $creatorId, ?FixedAssetCategory $existing = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            throw new FixedAssetException('Category code is required.');
        }

        // Unique per company, including soft-deleted rows (a
        // deactivated code is never reused).
        $clash = FixedAssetCategory::withTrashed()
            ->where('created_by', $creatorId)
            ->where('code', $code)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($clash) {
            throw new FixedAssetException("Category code {$code} already exists for this company.");
        }

        $classification = (string) ($data['classification'] ?? FixedAssetCategory::CLASSIFICATION_TANGIBLE);
        if (! in_array($classification, FixedAssetCategory::CLASSIFICATIONS, true)) {
            throw new FixedAssetException('Invalid category classification.');
        }

        $lifeUnit = (string) ($data['default_life_unit'] ?? FixedAssetCategory::LIFE_UNIT_YEAR);
        if (! in_array($lifeUnit, FixedAssetCategory::LIFE_UNITS, true)) {
            throw new FixedAssetException('Invalid default life unit.');
        }

        $method = (string) ($data['depreciation_method'] ?? FixedAssetCategory::METHOD_STRAIGHT_LINE);
        if (! in_array($method, FixedAssetCategory::METHODS, true)) {
            throw new FixedAssetException('Invalid depreciation method.');
        }
        // v2 methods are named in the plan but not implemented
        // until Phase 3 (reducing_balance) / v2 (units). Refuse
        // them now instead of silently storing a method the
        // engine cannot run.
        if ($method !== FixedAssetCategory::METHOD_STRAIGHT_LINE) {
            throw new FixedAssetException('Only straight-line depreciation is available in v1.');
        }

        $residualType = (string) ($data['residual_value_type'] ?? FixedAssetCategory::RESIDUAL_NONE);
        if (! in_array($residualType, FixedAssetCategory::RESIDUAL_TYPES, true)) {
            throw new FixedAssetException('Invalid residual value rule.');
        }

        $life = $data['default_useful_life'] ?? null;
        if ($life !== null && $life !== '' && (! is_numeric($life) || (int) $life <= 0)) {
            throw new FixedAssetException('Default useful life must be a positive number.');
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'code' => $code,
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => $data['description'] ?? null,
            'classification' => $classification,
            'default_useful_life' => $life === '' ? null : ($life === null ? null : (int) $life),
            'default_life_unit' => $lifeUnit,
            'depreciation_method' => $method,
            'depreciation_rate' => $this->decOrNull($data['depreciation_rate'] ?? null),
            'residual_value_type' => $residualType,
            'residual_value_percent' => $this->decOrNull($data['residual_value_percent'] ?? null),
            'residual_value' => $this->decOrNull($data['residual_value'] ?? null),
            'capitalization_threshold' => $this->decOrNull($data['capitalization_threshold'] ?? null),
            'asset_account_id' => $data['asset_account_id'] ?? null,
            'accum_depreciation_account_id' => $data['accum_depreciation_account_id'] ?? null,
            'depreciation_expense_account_id' => $data['depreciation_expense_account_id'] ?? null,
            'impairment_account_id' => $data['impairment_account_id'] ?? null,
            'disposal_gain_account_id' => $data['disposal_gain_account_id'] ?? null,
            'disposal_loss_account_id' => $data['disposal_loss_account_id'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    public function createLocation(array $data, int $creatorId, int $userId): FixedAssetLocation
    {
        $data = $this->normalizeLocationData($data, $creatorId);

        return DB::transaction(function () use ($data, $creatorId, $userId) {
            $location = FixedAssetLocation::create($data + [
                'created_by' => $creatorId,
                'company_id' => $creatorId,
            ]);

            $this->logAudit($creatorId, null, 'fixed_asset_location', $location->id, 'created', $userId, null, $location->fresh()->toArray());

            return $location->fresh();
        });
    }

    public function updateLocation(FixedAssetLocation $location, array $data, int $creatorId, int $userId): FixedAssetLocation
    {
        $this->assertSameCompany($location, $creatorId);
        $data = $this->normalizeLocationData($data, $creatorId, $location);

        return DB::transaction(function () use ($location, $data, $creatorId, $userId) {
            $before = $location->fresh()->toArray();
            $location->update($data);
            $this->logAudit($creatorId, null, 'fixed_asset_location', $location->id, 'updated', $userId, $before, $location->fresh()->toArray());

            return $location->fresh();
        });
    }

    public function deactivateLocation(FixedAssetLocation $location, int $creatorId, int $userId): FixedAssetLocation
    {
        $this->assertSameCompany($location, $creatorId);

        if (FixedAsset::where('created_by', $creatorId)->where('location_id', $location->id)->exists()) {
            throw new FixedAssetException('Location is still used by assets and cannot be deactivated.');
        }

        return DB::transaction(function () use ($location, $creatorId, $userId) {
            $before = $location->fresh()->toArray();
            $location->delete();
            $this->logAudit($creatorId, null, 'fixed_asset_location', $location->id, 'deactivated', $userId, $before, null);

            return $location->fresh();
        });
    }

    protected function normalizeLocationData(array $data, int $creatorId, ?FixedAssetLocation $existing = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            throw new FixedAssetException('Location code is required.');
        }

        $clash = FixedAssetLocation::withTrashed()
            ->where('created_by', $creatorId)
            ->where('code', $code)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($clash) {
            throw new FixedAssetException("Location code {$code} already exists for this company.");
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'code' => $code,
            'name' => trim((string) ($data['name'] ?? '')),
            'room' => $data['room'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }

    // ------------------------------------------------------------------
    // Approvals — no self-approval, at any value (spec §3, §24)
    // ------------------------------------------------------------------

    public function requestApproval(
        FixedAsset $asset,
        string $transactionType,
        ?int $transactionId,
        int $requestedBy,
        ?string $amountBase = null,
        int $level = 1
    ): FixedAssetApproval {
        $this->assertSameCompany($asset, (int) $asset->created_by);

        if (! in_array($transactionType, FixedAssetApproval::TRANSACTION_TYPES, true)) {
            throw new FixedAssetException('Invalid approval transaction type.');
        }

        return DB::transaction(function () use ($asset, $transactionType, $transactionId, $requestedBy, $amountBase, $level) {
            $approval = FixedAssetApproval::create([
                'created_by' => (int) $asset->created_by,
                'asset_id' => $asset->id,
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
                'level' => $level,
                'requested_by' => $requestedBy,
                'decision' => FixedAssetApproval::DECISION_PENDING,
                'amount_base' => $amountBase !== null ? $this->dec($amountBase) : null,
            ]);

            $this->logAudit((int) $asset->created_by, $asset->id, 'fixed_asset_approval', $approval->id, 'requested', $requestedBy, null, $approval->fresh()->toArray());

            return $approval->fresh();
        });
    }

    public function decideApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $this->assertSameCompany($approval, (int) $approval->created_by);

        if (! $approval->isPending()) {
            throw new FixedAssetException('Only a pending approval can be decided.');
        }

        if (! in_array($decision, [FixedAssetApproval::DECISION_APPROVED, FixedAssetApproval::DECISION_RETURNED, FixedAssetApproval::DECISION_REJECTED], true)) {
            throw new FixedAssetException('Invalid approval decision.');
        }

        // Segregation of duties (spec §3): no user approves their
        // own transaction, at any value.
        if ($approverId === (int) $approval->requested_by) {
            throw new FixedAssetException('You cannot approve a transaction you requested yourself.');
        }

        return DB::transaction(function () use ($approval, $approverId, $decision, $comment) {
            $before = $approval->fresh()->toArray();
            $approval->update([
                'approver_id' => $approverId,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            $this->logAudit((int) $approval->created_by, $approval->asset_id, 'fixed_asset_approval', $approval->id, 'decided:'.$decision, $approverId, $before, $approval->fresh()->toArray());

            return $approval->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Status history + audit trail (append-only, spec §24)
    // ------------------------------------------------------------------

    public function recordStatusChange(FixedAsset $asset, ?string $fromStatus, string $toStatus, int $changedBy, ?string $reason = null): FixedAssetStatusHistory
    {
        $this->assertSameCompany($asset, (int) $asset->created_by);

        if (! in_array($toStatus, FixedAsset::STATUSES, true)) {
            throw new FixedAssetException('Invalid asset status.');
        }

        return DB::transaction(function () use ($asset, $fromStatus, $toStatus, $changedBy, $reason) {
            $history = FixedAssetStatusHistory::create([
                'created_by' => (int) $asset->created_by,
                'asset_id' => $asset->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $changedBy,
                'reason' => $reason,
                'changed_at' => now(),
            ]);

            $asset->update(['status' => $toStatus]);

            $this->logAudit((int) $asset->created_by, $asset->id, 'fixed_asset', $asset->id, 'status:'.$toStatus, $changedBy, ['status' => $fromStatus], ['status' => $toStatus]);

            return $history;
        });
    }

    public function logAudit(
        int $creatorId,
        ?int $assetId,
        string $entity,
        ?int $entityId,
        string $action,
        int $actorId,
        ?array $before,
        ?array $after,
        ?string $reason = null
    ): FixedAssetAuditEvent {
        return FixedAssetAuditEvent::create([
            'created_by' => $creatorId,
            'asset_id' => $assetId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => $actorId,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
        ]);
    }

    // ------------------------------------------------------------------
    // Money helpers — bcmath only, never float (plan §5)
    // ------------------------------------------------------------------

    public function dec(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    protected function decOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return bcadd((string) $value, '0', 4);
    }
}
