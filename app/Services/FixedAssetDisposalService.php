<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetMaintenanceRequest;
use App\Models\FixedAssetTransfer;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\FixedAssetSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 5 — Disposal (plan §5, §10; spec §16, §17).
 *
 * Workflow: request → approval → execution (or rejected /
 * cancelled). Only an approved disposal can be executed — the
 * service refuses execution of anything else. Execution first
 * brings depreciation up to the disposal date (Phase 3
 * catch-up, authorised by the approved disposal), then posts
 * ONE balanced disposal journal via JournalEntryPoster only:
 *
 *   Dr Cash/Bank (actual proceeds, 12/11) or Dr Customers
 *       (411, sale on credit)
 *   Dr Accumulated depreciation (328)
 *   Dr Loss on disposal (6831/683, when proceeds < book value)
 *   Cr Fixed asset at cost (321)
 *   Cr Gain on disposal (763/763, when proceeds > book value)
 *
 * Gain/loss = ACTUAL proceeds − book value at disposal date
 * (book value = capitalized cost − opening accumulated
 * depreciation − depreciation posted up to the disposal date,
 * including the catch-up). The expected proceeds recorded at
 * request time never enter the journal.
 *
 * After execution the asset is disposed (sale / scrapping /
 * donation) or written_off (loss write-off, proceeds 0) and the
 * Phase 2/3/4 guards refuse it for assignment, transfer,
 * maintenance and depreciation. The asset row is never
 * deleted — it stays in the register with full history.
 *
 * Account resolution (plan §7): the gain/loss accounts come
 * from the category (disposal_gain_account_id /
 * disposal_loss_account_id) with a PGC MOZ fallback — gain
 * 7631 (Alienação) → 763 (Gains on Capital Investments) → 76,
 * loss 6831 (Alienação) → 683 (Losses on Capital Investments)
 * → 68. The asset / accumulated-depreciation accounts follow
 * the same asset-override → category → class 32 cascade as
 * capitalization / depreciation. Amounts are rounded to 2dp
 * BEFORE the gain/loss is computed, so the journal balances
 * exactly (the poster rounds every line to 2dp).
 */
class FixedAssetDisposalService
{
    public function __construct(
        protected FixedAssetService $assets,
        protected FixedAssetAcquisitionService $acquisitions,
        protected FixedAssetDepreciationService $depreciation,
        protected FixedAssetAssignmentService $assignments,
        protected JournalEntryPoster $poster,
        protected ChartAccountResolver $resolver,
        protected ?FixedAssetMaintenanceService $maintenance = null,
        protected ?FixedAssetTransferService $transfers = null,
    ) {
        // Phase 6 — the maintenance / transfer services are only
        // needed to close open workflows at disposal execution.
        // They are nullable so existing constructions (notably the
        // Phase 5 tests) keep working; when absent they are
        // resolved from the container at execution time.
        $this->maintenance ??= app(FixedAssetMaintenanceService::class);
        $this->transfers ??= app(FixedAssetTransferService::class);
    }

    // ------------------------------------------------------------------
    // Request
    // ------------------------------------------------------------------

    public function requestDisposal(FixedAsset $asset, array $data, int $creatorId, int $userId): FixedAssetDisposal
    {
        $this->assertDisposable($asset, $creatorId, (string) ($data['method'] ?? ''));

        $method = (string) $data['method'];
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new FixedAssetException('A reason for the disposal is required.');
        }
        $expected = (string) ($data['expected_proceeds'] ?? '0');
        if (! is_numeric($expected) || bccomp($expected, '0', 4) < 0) {
            throw new FixedAssetException('Expected proceeds must be zero or more.');
        }
        if ($method !== FixedAssetDisposal::METHOD_SALE && bccomp($expected, '0', 4) > 0) {
            throw new FixedAssetException('Expected proceeds must be 0 for scrapping, donation and write-off.');
        }

        return DB::transaction(function () use ($asset, $creatorId, $userId, $method, $reason, $expected, $data) {
            $previousStatus = $asset->status;

            $disposal = FixedAssetDisposal::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'method' => $method,
                'status' => FixedAssetDisposal::STATUS_REQUESTED,
                'reason' => $reason,
                'expected_proceeds' => $expected,
                'buyer_name' => $data['buyer_name'] ?? null,
                'previous_status' => $previousStatus,
                'requested_by' => $userId,
                'requested_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Approval levels (plan §8, same engine as
            // acquisitions): level 1 always, level 2 when the
            // amount reaches the configured threshold (default
            // MZN 100,000). The amount at stake in a disposal is
            // the asset's book value — what leaves the books —
            // not the expected proceeds.
            $bookValue = (string) $asset->current_book_value;
            foreach (FixedAssetSettings::approvalLevels($creatorId, $bookValue) as $level) {
                $exists = FixedAssetApproval::where('created_by', $creatorId)
                    ->where('asset_id', $asset->id)
                    ->where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
                    ->where('transaction_id', $disposal->id)
                    ->where('level', (int) $level['level'])
                    ->where('decision', FixedAssetApproval::DECISION_PENDING)
                    ->exists();
                if (! $exists) {
                    $this->assets->requestApproval(
                        $asset,
                        FixedAssetApproval::TYPE_DISPOSAL,
                        $disposal->id,
                        $userId,
                        $bookValue,
                        (int) $level['level']
                    );
                }
            }

            $this->assets->recordStatusChange($asset->fresh(), $previousStatus, FixedAsset::STATUS_DISPOSAL_PENDING, $userId, 'Disposal requested ('.$method.')');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_disposal', $disposal->id, 'requested', $userId, null, $disposal->fresh()->toArray(), $reason);

            return $disposal->fresh();
        });
    }

    /** The open (requested/approved) disposal for an asset, if any. */
    public function activeDisposalFor(FixedAsset $asset, int $creatorId): ?FixedAssetDisposal
    {
        return FixedAssetDisposal::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->whereIn('status', FixedAssetDisposal::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Approval — mirrors the maintenance decide pattern (Phase 4):
    // the permission check lives in the controller, the
    // no-self-approval rule lives in decideApproval().
    // ------------------------------------------------------------------

    public function decideDisposalApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $disposal = $this->disposalOf($approval);
        $this->assets->assertSameCompany($disposal, (int) $approval->created_by);

        if ($approval->transaction_type !== FixedAssetApproval::TYPE_DISPOSAL) {
            throw new FixedAssetException('This approval is not a disposal approval.');
        }

        if ($decision === FixedAssetApproval::DECISION_RETURNED) {
            throw new FixedAssetException('A disposal request cannot be returned for correction — approve or reject it.');
        }

        if ((int) $approval->level === 2) {
            $levelOne = FixedAssetApproval::where('created_by', $approval->created_by)
                ->where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
                ->where('transaction_id', $approval->transaction_id)
                ->where('level', 1)
                ->where('decision', FixedAssetApproval::DECISION_APPROVED)
                ->first();
            if ($levelOne && (int) $levelOne->approver_id === $approverId) {
                throw new FixedAssetException('The second approval level must be a different user than the first.');
            }
        }

        $decided = $this->assets->decideApproval($approval, $approverId, $decision, $comment);

        if ($decision === FixedAssetApproval::DECISION_APPROVED) {
            // Advance only when EVERY required level is approved.
            $pending = FixedAssetApproval::where('created_by', (int) $approval->created_by)
                ->where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
                ->where('transaction_id', $disposal->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->exists();
            if (! $pending) {
                DB::transaction(function () use ($disposal, $approverId) {
                    $disposal->update([
                        'status' => FixedAssetDisposal::STATUS_APPROVED,
                        'approved_by' => $approverId,
                        'approved_at' => now(),
                    ]);
                    $this->assets->logAudit((int) $disposal->created_by, $disposal->asset_id, 'fixed_asset_disposal', $disposal->id, 'approved', $approverId, null, $disposal->fresh()->toArray());
                });
            }
        }

        if ($decision === FixedAssetApproval::DECISION_REJECTED) {
            DB::transaction(function () use ($disposal, $approverId, $comment) {
                $disposal->update(['status' => FixedAssetDisposal::STATUS_REJECTED]);
                $asset = $disposal->asset->fresh();
                // The asset goes back to where it was before the
                // request — it was never off the books.
                $this->assets->recordStatusChange($asset, $asset->status, $disposal->previous_status ?? FixedAsset::STATUS_AVAILABLE, $approverId, 'Disposal rejected');
                $this->assets->logAudit((int) $disposal->created_by, $disposal->asset_id, 'fixed_asset_disposal', $disposal->id, 'rejected', $approverId, null, $disposal->fresh()->toArray(), $comment);
            });
        }

        return $decided->fresh();
    }

    public function disposalOf(FixedAssetApproval $approval): FixedAssetDisposal
    {
        return FixedAssetDisposal::where('created_by', $approval->created_by)
            ->where('id', $approval->transaction_id)
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Execution — approval + catch-up + journal + status flip
    // ------------------------------------------------------------------

    public function executeDisposal(FixedAssetDisposal $disposal, array $data, int $creatorId, int $userId): FixedAssetDisposal
    {
        $this->assets->assertSameCompany($disposal, $creatorId);

        if (! $disposal->isApproved()) {
            throw new FixedAssetException('Only an approved disposal can be executed.');
        }

        $asset = $disposal->asset;
        if (! $asset) {
            throw new FixedAssetException('The disposed asset no longer exists.');
        }

        $actual = (string) ($data['actual_proceeds'] ?? '0');
        if (! is_numeric($actual) || bccomp($actual, '0', 4) < 0) {
            throw new FixedAssetException('Actual proceeds must be zero or more.');
        }
        if ($disposal->method !== FixedAssetDisposal::METHOD_SALE && bccomp($actual, '0', 4) > 0) {
            throw new FixedAssetException('Actual proceeds must be 0 for scrapping, donation and write-off.');
        }

        $disposalDate = isset($data['disposal_date']) && $data['disposal_date'] !== ''
            ? Carbon::parse($data['disposal_date'])->startOfDay()
            : now()->startOfDay();
        $year = (int) $disposalDate->format('Y');
        if ($this->acquisitions->isFiscalYearClosed($creatorId, $year)) {
            throw new FixedAssetException('Fiscal year '.$year.' is already closed. Disposal is blocked for closed periods.');
        }

        $proceedsReceived = (bool) ($data['proceeds_received'] ?? true);

        return DB::transaction(function () use ($disposal, $asset, $creatorId, $userId, $actual, $disposalDate, $proceedsReceived) {
            // 1. Depreciation catch-up: post every unposted period
            // up to the disposal date, authorised by this approved
            // disposal. A disposal never leaves unposted
            // depreciation behind.
            $this->depreciation->postCatchUpForAsset(
                $asset->fresh(),
                $disposalDate,
                $creatorId,
                $userId,
                (int) $disposal->approved_by
            );

            // 2. Book value at disposal date, from the components
            // (never the maintained current_book_value field, so a
            // stale value cannot leak into the journal).
            $fresh = $asset->fresh();
            $cost = (string) $fresh->capitalized_cost;
            $posted = $this->depreciation->postedTotalForAsset((int) $fresh->id, $creatorId);
            $accum = bcadd((string) ($fresh->opening_accumulated_depreciation ?? '0'), $posted, 4);
            $bookValue = bcsub($cost, $accum, 4);
            if (bccomp($bookValue, '0', 4) < 0) {
                throw new FixedAssetException('Book value at disposal date is negative ('.$bookValue.'). Check the depreciation schedule before disposing.');
            }

            // 3. Round every component to 2dp FIRST, then derive
            // the gain/loss — the poster rounds every line to 2dp,
            // so a 4dp gain/loss could unbalance the journal by a
            // cent. Signed: + gain, − loss.
            $cost2 = number_format((float) $cost, 2, '.', '');
            $accum2 = number_format((float) $accum, 2, '.', '');
            $proceeds2 = number_format((float) $actual, 2, '.', '');
            $book2 = bcsub($cost2, $accum2, 2);
            $gainLoss2 = bcsub($proceeds2, $book2, 2);

            // 4. A lost/stolen asset may still have an active
            // assignment (the custodian lost it) — close it now,
            // or the register would keep showing a disposed asset
            // as "in the hands of" someone. A sale/scrapping/
            // donation never reaches here assigned: the request
            // is refused while the asset is assigned.
            $activeAssignment = $this->assignments->activeAssignmentFor($fresh, $creatorId);
            if ($activeAssignment) {
                if ($activeAssignment->status === FixedAssetAssignment::STATUS_ACCEPTED) {
                    $this->assignments->returnAssignment($activeAssignment, $creatorId, $userId, 'Asset disposed ('.$disposal->method.')');
                } elseif (in_array($activeAssignment->status, [FixedAssetAssignment::STATUS_REQUESTED, FixedAssetAssignment::STATUS_APPROVED], true)) {
                    $this->assignments->cancelAssignment($activeAssignment, $creatorId, $userId);
                } else {
                    $this->assignments->refuseAssignment($activeAssignment, $creatorId, $userId, 'Asset disposed ('.$disposal->method.')');
                }
                $fresh = $asset->fresh();
            }

            // 4b. Phase 6 — close open maintenance requests and
            // in-flight transfers. A disposal requested while a
            // maintenance request was still pending, or a transfer
            // was still requested / approved / handed over, used to
            // leave those workflows open forever on a disposed
            // asset (Phase 5 rough edge). They are closed here,
            // inside the same transaction, before the journal and
            // the status flip — so the asset can never end up
            // disposed with live work attached to it.
            //
            // Maintenance is cancelled through the normal service
            // (its status restore is a no-op while the asset is
            // disposal_pending, and restores the pre-maintenance
            // status if the request had already moved the asset to
            // under_maintenance). Transfers go through the
            // disposal-aware cancellation, which never touches the
            // asset status — the disposal owns it from here — and
            // which also covers a handed-over transfer that can
            // never be received once the asset is gone.
            $openMaintenance = FixedAssetMaintenanceRequest::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', [
                    FixedAssetMaintenanceRequest::STATUS_REQUESTED,
                    FixedAssetMaintenanceRequest::STATUS_APPROVED,
                    FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
                ])
                ->get();
            foreach ($openMaintenance as $request) {
                $this->maintenance->cancelMaintenance($request, $creatorId, $userId);
            }

            $openTransfers = FixedAssetTransfer::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', [
                    FixedAssetTransfer::STATUS_REQUESTED,
                    FixedAssetTransfer::STATUS_APPROVED,
                    FixedAssetTransfer::STATUS_HANDED_OVER,
                ])
                ->get();
            foreach ($openTransfers as $transfer) {
                $this->transfers->cancelTransferForDisposal($transfer, $creatorId, $userId);
            }

            $fresh = $fresh->fresh();

            // 5. The disposal journal (poster only).
            $journal = null;
            $lines = [];
            if (bccomp($proceeds2, '0', 2) > 0) {
                $lines[] = [
                    'account' => $this->resolveProceedsAccount($creatorId, $proceedsReceived),
                    'debit' => (float) $proceeds2,
                    'description' => 'Disposal proceeds '.$fresh->code.($disposal->buyer_name ? ' ('.$disposal->buyer_name.')' : ''),
                ];
            }
            if (bccomp($accum2, '0', 2) > 0) {
                $lines[] = [
                    'account' => $this->depreciation->resolveAccumAccount($fresh, $creatorId),
                    'debit' => (float) $accum2,
                    'description' => 'Accumulated depreciation '.$fresh->code,
                ];
            }
            if (bccomp($gainLoss2, '0', 2) < 0) {
                $lines[] = [
                    'account' => $this->resolveLossAccount($fresh, $creatorId),
                    'debit' => (float) bcsub('0', $gainLoss2, 2),
                    'description' => 'Loss on disposal '.$fresh->code,
                ];
            }
            if (bccomp($cost2, '0', 2) > 0) {
                $lines[] = [
                    'account' => $this->resolveAssetAccount($fresh, $creatorId),
                    'credit' => (float) $cost2,
                    'description' => 'Disposal of fixed asset '.$fresh->code,
                ];
            }
            if (bccomp($gainLoss2, '0', 2) > 0) {
                $lines[] = [
                    'account' => $this->resolveGainAccount($fresh, $creatorId),
                    'credit' => (float) $gainLoss2,
                    'description' => 'Gain on disposal '.$fresh->code,
                ];
            }

            if ($lines !== []) {
                $journal = $this->poster->post(
                    $creatorId,
                    $disposalDate->format('Y-m-d'),
                    'FA-DSP-'.$disposal->id,
                    'Disposal of fixed asset '.$fresh->code.' ('.$fresh->name.') — '.$disposal->method,
                    $lines
                );

                FixedAssetAccountingEntry::create([
                    'created_by' => $creatorId,
                    'asset_id' => $fresh->id,
                    'transaction_type' => FixedAssetAccountingEntry::TYPE_DISPOSAL,
                    'transaction_id' => $disposal->id,
                    'journal_id' => $journal->id,
                ]);
            }

            // 6. Status flip + book value to zero. The asset stays
            // in the register with full history — never deleted.
            $newStatus = $disposal->method === FixedAssetDisposal::METHOD_WRITE_OFF
                ? FixedAsset::STATUS_WRITTEN_OFF
                : FixedAsset::STATUS_DISPOSED;

            $disposal->update([
                'status' => FixedAssetDisposal::STATUS_EXECUTED,
                'actual_proceeds' => $proceeds2,
                'proceeds_received' => $proceedsReceived,
                'disposal_date' => $disposalDate->format('Y-m-d'),
                'journal_id' => $journal ? $journal->id : null,
                'gain_loss_amount' => $gainLoss2,
                'book_value_at_disposal' => $book2,
                'accumulated_depreciation_at_disposal' => $accum2,
                'executed_by' => $userId,
                'executed_at' => now(),
            ]);

            $fresh->update(['current_book_value' => '0.0000']);

            // Phase 6 — cancel the remaining planned schedule
            // rows. The catch-up (step 1) posted every period up
            // to the disposal date, so what is left is future
            // months that must never post: the Phase 3 engine only
            // computes PLANNED rows, so a cancelled row is
            // invisible to every future run. The rows are kept, not
            // deleted, so the schedule still shows what was planned
            // and what the disposal cut short.
            FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $fresh->id)
                ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
                ->update(['status' => FixedAssetDepreciationSchedule::STATUS_CANCELLED]);

            $this->assets->recordStatusChange($fresh->fresh(), $fresh->status, $newStatus, $userId, 'Disposed ('.$disposal->method.')');
            $this->assets->logAudit($creatorId, $fresh->id, 'fixed_asset_disposal', $disposal->id, 'executed', $userId, null, $disposal->fresh()->toArray());

            return $disposal->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Cancel — requested only; the asset goes back to its status
    // before the request.
    // ------------------------------------------------------------------

    public function cancelDisposal(FixedAssetDisposal $disposal, int $creatorId, int $userId, ?string $reason = null): FixedAssetDisposal
    {
        $this->assets->assertSameCompany($disposal, $creatorId);

        if (! $disposal->isRequested()) {
            throw new FixedAssetException('Only a requested disposal can be cancelled.');
        }

        return DB::transaction(function () use ($disposal, $creatorId, $userId, $reason) {
            $disposal->update(['status' => FixedAssetDisposal::STATUS_CANCELLED]);
            $asset = $disposal->asset->fresh();
            $this->assets->recordStatusChange($asset, $asset->status, $disposal->previous_status ?? FixedAsset::STATUS_AVAILABLE, $userId, 'Disposal cancelled');
            $this->assets->logAudit($creatorId, $disposal->asset_id, 'fixed_asset_disposal', $disposal->id, 'cancelled', $userId, null, $disposal->fresh()->toArray(), $reason);

            return $disposal->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * Which assets can be disposed, and how. A write-off is only
     * for an asset that is gone (lost / stolen) — a damaged asset
     * is scrapped, not written off. An assigned asset must be
     * returned first; an asset in the middle of another workflow
     * (maintenance / verification / transfer) must finish it
     * first. Disposed / written-off assets can never be disposed
     * again — the Phase 2/3/4 guards already refuse them for
     * assignment, transfer, maintenance and depreciation.
     */
    public function assertDisposable(FixedAsset $asset, int $creatorId, string $method): void
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if (! in_array($method, FixedAssetDisposal::METHODS, true)) {
            throw new FixedAssetException('Invalid disposal method.');
        }

        if (in_array($asset->status, [FixedAsset::STATUS_DISPOSED, FixedAsset::STATUS_WRITTEN_OFF], true)) {
            throw new FixedAssetException("A {$asset->status} asset can never be disposed again.");
        }

        if ($asset->status === FixedAsset::STATUS_DISPOSAL_PENDING || $this->activeDisposalFor($asset, $creatorId)) {
            throw new FixedAssetException('This asset already has a disposal in progress.');
        }

        if ($method === FixedAssetDisposal::METHOD_WRITE_OFF) {
            if (! in_array($asset->status, [FixedAsset::STATUS_LOST, FixedAsset::STATUS_STOLEN], true)) {
                throw new FixedAssetException("Only a lost or stolen asset can be written off (asset is {$asset->status}). Use scrapping for a damaged asset.");
            }

            return;
        }

        if (in_array($asset->status, [FixedAsset::STATUS_LOST, FixedAsset::STATUS_STOLEN], true)) {
            throw new FixedAssetException("A {$asset->status} asset can only be written off.");
        }

        if ($asset->status === FixedAsset::STATUS_ASSIGNED) {
            throw new FixedAssetException('Return the assigned asset first — an assigned asset cannot be disposed.');
        }

        if ($asset->status === FixedAsset::STATUS_UNDER_MAINTENANCE) {
            throw new FixedAssetException('Complete or cancel the open maintenance first — an asset under maintenance cannot be disposed.');
        }

        if ($asset->status === FixedAsset::STATUS_UNDER_VERIFICATION) {
            throw new FixedAssetException('Wait for the open verification campaign to close — an asset under verification cannot be disposed.');
        }

        if ($asset->status === FixedAsset::STATUS_IN_TRANSFER) {
            throw new FixedAssetException('Wait for the open transfer to finish — an asset in transfer cannot be disposed.');
        }

        if (! in_array($asset->status, [FixedAsset::STATUS_AVAILABLE, FixedAsset::STATUS_ACTIVE, FixedAsset::STATUS_DAMAGED, FixedAsset::STATUS_FULLY_DEPRECIATED, FixedAsset::STATUS_OUT_OF_SERVICE, FixedAsset::STATUS_IMPAIRED], true)) {
            throw new FixedAssetException("Only an available, damaged or fully depreciated asset can be disposed (asset is {$asset->status}).");
        }
    }

    // ------------------------------------------------------------------
    // Account resolution
    // ------------------------------------------------------------------

    /** Asset account: asset override, else category default, else class 32. */
    public function resolveAssetAccount(FixedAsset $asset, int $creatorId): int
    {
        $id = (int) ($asset->asset_account_id ?? 0);
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $category = $asset->category;
        $id = $category ? (int) ($category->asset_account_id ?? 0) : 0;
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '32');
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('No fixed asset account is configured for this category and no class 32 account exists. Set the asset account on the category first.');
    }

    /**
     * Gain on disposal: category override, else PGC MOZ 7631
     * (Alienação) → 763 (Gains on Capital Investments) → 76.
     */
    public function resolveGainAccount(FixedAsset $asset, int $creatorId): int
    {
        $category = $asset->category;
        $id = $category ? (int) ($category->disposal_gain_account_id ?? 0) : 0;
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        foreach (['7631', '763', '76'] as $code) {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
            if ($id) {
                return (int) $id;
            }
        }

        throw new FixedAssetException('No gain-on-disposal account is configured for this category and no class 76 account exists. Set the disposal gain account on the category first.');
    }

    /**
     * Loss on disposal: category override, else PGC MOZ 6831
     * (Alienação) → 683 (Losses on Capital Investments) → 68.
     */
    public function resolveLossAccount(FixedAsset $asset, int $creatorId): int
    {
        $category = $asset->category;
        $id = $category ? (int) ($category->disposal_loss_account_id ?? 0) : 0;
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        foreach (['6831', '683', '68'] as $code) {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
            if ($id) {
                return (int) $id;
            }
        }

        throw new FixedAssetException('No loss-on-disposal account is configured for this category and no class 68 account exists. Set the disposal loss account on the category first.');
    }

    /**
     * Proceeds account (plan §7: Dr Cash/Bank). Received now →
     * bank 12, else cash 11; sold on credit → customers 411,
     * else 41.
     */
    public function resolveProceedsAccount(int $creatorId, bool $received): int
    {
        foreach ($received ? ['12', '11'] : ['411', '41'] as $code) {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
            if ($id) {
                return (int) $id;
            }
        }

        throw new FixedAssetException($received
            ? 'No bank or cash account (12/11) exists. Create a bank or cash account in the chart of accounts first.'
            : 'No customers account (411/41) exists. Create a customers account in the chart of accounts first.');
    }
}
