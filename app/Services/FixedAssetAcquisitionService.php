<?php

namespace App\Services;

use App\Exceptions\FixedAssetException;
use App\Models\Bill;
use App\Models\BillProduct;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetAcquisitionCost;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetCapitalization;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\PurchaseDeliveryNoteItem;
use App\Models\PurchaseProduct;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets Phase 1 — Register + Acquisition + Capitalization
 * (plan §5, §10; spec §6, §7, §8, §9).
 *
 * Owns: the cost builder (spec §8 — recoverable VAT is never
 * part of capitalized cost), asset registration from every
 * acquisition source (PO line / receipt / invoice + direct /
 * donation / opening balance), the review → approval →
 * capitalization workflow (no self-approval, per-company
 * levels, every decision recorded) and the capitalization
 * journal (JournalEntryPoster only) + depreciation schedule
 * activation.
 *
 * All writes are company-scoped via creatorId(): every method
 * asserts the row belongs to the caller's company before it
 * touches it (AC11 pattern from Contract Management).
 */
class FixedAssetAcquisitionService
{
    public function __construct(
        protected FixedAssetService $assets,
        protected FixedAssetNumberingService $numbering,
        protected JournalEntryPoster $poster,
        protected ChartAccountResolver $resolver,
    ) {
    }

    // ------------------------------------------------------------------
    // Cost builder (spec §8) — bcmath only, never float
    // ------------------------------------------------------------------

    /**
     * Build the capitalized cost from cost components.
     *
     * Each component: gross amount (VAT included where the source
     * document charged it), the VAT inside it, and whether that
     * VAT is recoverable. Recoverable VAT is subtracted — it must
     * NOT form part of asset cost. Non-recoverable VAT stays in
     * the cost (it is a "non recoverable taxes" component).
     * Discounts subtract. Returns the normalized lines plus the
     * capitalized totals in original currency and base currency.
     *
     * @return array{lines: array<int, array<string, mixed>>, capitalized_cost: string, capitalized_base: string}
     */
    public function buildCost(array $components, string $currency, string $exchangeRate): array
    {
        $exchangeRate = $this->rate($exchangeRate);
        $lines = [];
        $capitalized = '0';
        $capitalizedBase = '0';

        foreach ($components as $component) {
            $type = (string) ($component['component_type'] ?? '');
            if (! in_array($type, FixedAssetAcquisitionCost::TYPES, true)) {
                throw new FixedAssetException('Invalid cost component type.');
            }

            $gross = $this->dec($component['gross_amount'] ?? '0');
            $vat = $this->dec($component['vat_amount'] ?? '0');
            if (bccomp($gross, '0', 4) < 0 || bccomp($vat, '0', 4) < 0) {
                throw new FixedAssetException('Cost amounts must not be negative.');
            }
            if (bccomp($vat, $gross, 4) > 0) {
                throw new FixedAssetException('VAT amount must not exceed the gross amount.');
            }

            $recoverable = (bool) ($component['vat_recoverable'] ?? false);
            // The net contribution: recoverable VAT comes out,
            // non-recoverable VAT stays in the cost.
            $net = bcsub($gross, $recoverable ? $vat : '0', 4);
            $signed = $type === FixedAssetAcquisitionCost::TYPE_DISCOUNT
                ? bcmul($net, '-1', 4)
                : $net;
            $base = bcmul($signed, $exchangeRate, 4);

            $lines[] = [
                'component_type' => $type,
                'gross_amount' => $gross,
                'vat_amount' => $vat,
                'vat_recoverable' => $recoverable,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount_base' => $base,
                'source_document_reference' => $component['source_document_reference'] ?? null,
                'notes' => $component['notes'] ?? null,
            ];

            $capitalized = bcadd($capitalized, $signed, 4);
            $capitalizedBase = bcadd($capitalizedBase, $base, 4);
        }

        if ($lines === []) {
            throw new FixedAssetException('At least one cost component is required.');
        }

        $hasPrice = collect($lines)->contains(
            fn ($line) => $line['component_type'] === FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE
        );
        if (! $hasPrice) {
            throw new FixedAssetException('A purchase price cost component is required.');
        }

        return [
            'lines' => $lines,
            'capitalized_cost' => $capitalized,
            'capitalized_base' => $capitalizedBase,
        ];
    }

    // ------------------------------------------------------------------
    // Registration — New Asset form (§7 fields)
    // ------------------------------------------------------------------

    /**
     * Create the asset master row (via allocateAndCreate — the
     * code is allocated at creation, never reused) together with
     * its acquisition + cost rows, all in DRAFT.
     *
     * @return array{assets: array<int, FixedAsset>, acquisitions: array<int, FixedAssetAcquisition>}
     */
    public function createAsset(array $data, int $creatorId, int $userId): array
    {
        $normalized = $this->normalizeAssetData($data, $creatorId);
        $count = max(1, min(100, (int) ($data['asset_count'] ?? 1)));

        return DB::transaction(function () use ($normalized, $count, $creatorId, $userId) {
            $assets = [];
            $acquisitions = [];

            for ($i = 1; $i <= $count; $i++) {
                $share = $this->splitCost($normalized['cost'], $count, $i);
                $assetData = $normalized['asset'];
                $assetData['capitalized_cost'] = $share['capitalized_cost'];
                $assetData['amount_base'] = $share['capitalized_base'];
                $assetData['purchase_cost'] = $share['purchase_cost'];
                if ($count > 1) {
                    $assetData['name'] = $normalized['asset']['name'].' #'.$i;
                    if ($i > 1) {
                        $assetData['serial_number'] = null;
                    }
                }

                $asset = $this->numbering->allocateAndCreate(
                    $creatorId,
                    $normalized['category']->code,
                    $assetData
                );

                $acquisition = FixedAssetAcquisition::create([
                    'created_by' => $creatorId,
                    'asset_id' => $asset->id,
                    'source_type' => $normalized['acquisition']['source_type'],
                    'source_id' => $normalized['acquisition']['source_id'],
                    'source_reference' => $normalized['acquisition']['source_reference'],
                    'supplier' => $normalized['acquisition']['supplier'],
                    'purchase_date' => $normalized['acquisition']['purchase_date'],
                    'receipt_date' => $normalized['acquisition']['receipt_date'],
                    'currency' => $normalized['acquisition']['currency'],
                    'exchange_rate' => $normalized['acquisition']['exchange_rate'],
                    'status' => FixedAssetAcquisition::STATUS_DRAFT,
                    'requested_by' => $userId,
                    'notes' => $normalized['acquisition']['notes'],
                ]);

                foreach ($share['lines'] as $line) {
                    FixedAssetAcquisitionCost::create($line + [
                        'created_by' => $creatorId,
                        'acquisition_id' => $acquisition->id,
                    ]);
                }

                $this->assets->recordStatusChange($asset, null, FixedAsset::STATUS_DRAFT, $userId, 'Registered');
                $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_acquisition', $acquisition->id, 'created', $userId, null, $acquisition->fresh()->toArray());

                $assets[] = $asset->fresh();
                $acquisitions[] = $acquisition->fresh();
            }

            return ['assets' => $assets, 'acquisitions' => $acquisitions];
        });
    }

    /**
     * Edit a draft / returned asset (the "returned for
     * correction" path). Cost changes are refused once the asset
     * has left DRAFT — after capitalization the original cost is
     * locked (spec §9).
     */
    public function updateAsset(FixedAsset $asset, array $data, int $creatorId, int $userId): FixedAsset
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        $acquisition = $this->latestAcquisition($asset, $creatorId);
        if (! $acquisition || ! $acquisition->isEditable() || ! $asset->isDraft()) {
            throw new FixedAssetException('Only a draft or returned asset can be edited.');
        }

        $normalized = $this->normalizeAssetData($data, $creatorId, $asset);

        return DB::transaction(function () use ($asset, $acquisition, $normalized, $creatorId, $userId) {
            $before = $asset->fresh()->toArray();

            $asset->update($normalized['asset'] + [
                'capitalized_cost' => $normalized['cost']['capitalized_cost'],
                'amount_base' => $normalized['cost']['capitalized_base'],
                'purchase_cost' => $this->purchasePriceNet($normalized['cost']['lines']),
            ]);

            $acquisition->update($normalized['acquisition']);
            $acquisition->costs()->delete();
            foreach ($normalized['cost']['lines'] as $line) {
                FixedAssetAcquisitionCost::create($line + [
                    'created_by' => $creatorId,
                    'acquisition_id' => $acquisition->id,
                ]);
            }

            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset', $asset->id, 'updated', $userId, $before, $asset->fresh()->toArray());

            return $asset->fresh();
        });
    }

    /**
     * Acquisition from a purchasing source: a PO line, a bill
     * line or a delivery-note item. The source line prefills the
     * supplier, references, dates and the gross purchase price;
     * the VAT split is left for the user to confirm on the form
     * (the source line does not decompose VAT), and the link is
     * kept for traceability. Purchasing itself is never changed.
     */
    public function createFromSource(string $sourceType, int $sourceId, array $overrides, int $creatorId, int $userId): array
    {
        $prefill = $this->resolveSource($sourceType, $sourceId, $creatorId);

        $data = array_merge($prefill, $overrides);
        $data['source_type'] = $sourceType;
        $data['source_id'] = $sourceId;
        $data['source_reference'] = $prefill['source_reference'];

        return $this->createAsset($data, $creatorId, $userId);
    }

    /**
     * Resolve a purchasing source line into prefilled asset data.
     * Returns asset fields + a purchase_price cost component.
     */
    public function resolveSource(string $sourceType, int $sourceId, int $creatorId): array
    {
        return match ($sourceType) {
            FixedAssetAcquisition::SOURCE_PURCHASE_PRODUCT => $this->resolvePurchaseProduct($sourceId, $creatorId),
            FixedAssetAcquisition::SOURCE_BILL_PRODUCT => $this->resolveBillProduct($sourceId, $creatorId),
            FixedAssetAcquisition::SOURCE_DELIVERY_NOTE_ITEM => $this->resolveDeliveryNoteItem($sourceId, $creatorId),
            default => throw new FixedAssetException('Invalid acquisition source type.'),
        };
    }

    protected function resolvePurchaseProduct(int $sourceId, int $creatorId): array
    {
        $line = PurchaseProduct::find($sourceId);
        if (! $line) {
            throw new FixedAssetException('Purchase order line not found.');
        }
        $purchase = Purchase::find((int) $line->purchase_id);
        if (! $purchase || (int) $purchase->created_by !== $creatorId) {
            throw new FixedAssetException('Purchase order line not found for this company.');
        }

        return [
            'name' => $line->product ? (string) $line->product->name : 'Asset from PO '.$purchase->purchase_id,
            'supplier' => $purchase->vender ? (string) $purchase->vender->name : null,
            'po_reference' => (string) $purchase->purchase_id,
            'purchase_date' => $purchase->purchase_date,
            'source_reference' => (string) $purchase->purchase_id,
            'components' => [[
                'component_type' => FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE,
                'gross_amount' => $this->dec($line->total ?? '0'),
                'vat_amount' => '0.0000',
                'vat_recoverable' => false,
                'source_document_reference' => (string) $purchase->purchase_id,
            ]],
        ];
    }

    protected function resolveBillProduct(int $sourceId, int $creatorId): array
    {
        $line = BillProduct::find($sourceId);
        if (! $line) {
            throw new FixedAssetException('Bill line not found.');
        }
        $bill = Bill::find((int) $line->bill_id);
        if (! $bill || (int) $bill->created_by !== $creatorId) {
            throw new FixedAssetException('Bill line not found for this company.');
        }

        return [
            'name' => $line->product ? (string) $line->product->name : 'Asset from bill '.$bill->bill_id,
            'supplier' => $bill->vender ? (string) $bill->vender->name : null,
            'invoice_reference' => (string) $bill->bill_id,
            'purchase_date' => $bill->bill_date,
            'source_reference' => (string) $bill->bill_id,
            'components' => [[
                'component_type' => FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE,
                'gross_amount' => $this->dec($line->total ?? '0'),
                'vat_amount' => '0.0000',
                'vat_recoverable' => false,
                'source_document_reference' => (string) $bill->bill_id,
            ]],
        ];
    }

    protected function resolveDeliveryNoteItem(int $sourceId, int $creatorId): array
    {
        $item = PurchaseDeliveryNoteItem::find($sourceId);
        if (! $item) {
            throw new FixedAssetException('Delivery note item not found.');
        }
        // The relation on the item is note(), not purchaseDeliveryNote().
        $note = $item->note;
        if (! $note || (int) $note->created_by !== $creatorId) {
            throw new FixedAssetException('Delivery note item not found for this company.');
        }

        $gross = bcmul($this->dec($item->price ?? '0'), (string) ((int) ($item->delivered_qty ?? 0)), 4);

        return [
            'name' => $item->product ? (string) $item->product->name : 'Asset from delivery '.$note->note_number,
            'supplier' => $note->vender ? (string) $note->vender->name : null,
            'po_reference' => $note->purchase ? (string) $note->purchase->purchase_id : null,
            'receipt_date' => $note->delivery_date,
            'source_reference' => (string) $note->note_number,
            'components' => [[
                'component_type' => FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE,
                'gross_amount' => $gross,
                'vat_amount' => '0.0000',
                'vat_recoverable' => false,
                'source_document_reference' => (string) $note->note_number,
            ]],
        ];
    }

    // ------------------------------------------------------------------
    // Workflow (§9): draft → pending_review → pending_approval →
    // approved → capitalized; returned / rejected / cancelled
    // ------------------------------------------------------------------

    public function submitForReview(FixedAssetAcquisition $acquisition, int $creatorId, int $userId): FixedAssetAcquisition
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if (! in_array($acquisition->status, [FixedAssetAcquisition::STATUS_DRAFT, FixedAssetAcquisition::STATUS_RETURNED], true)) {
            throw new FixedAssetException('Only a draft or returned acquisition can be submitted for review.');
        }

        return DB::transaction(function () use ($acquisition, $creatorId, $userId) {
            $acquisition->update(['status' => FixedAssetAcquisition::STATUS_PENDING_REVIEW]);
            $this->assets->logAudit($creatorId, $acquisition->asset_id, 'fixed_asset_acquisition', $acquisition->id, 'submitted_for_review', $userId, null, null);

            return $acquisition->fresh();
        });
    }

    public function returnForCorrection(FixedAssetAcquisition $acquisition, int $creatorId, int $userId, ?string $reason = null): FixedAssetAcquisition
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if (! in_array($acquisition->status, [FixedAssetAcquisition::STATUS_PENDING_REVIEW, FixedAssetAcquisition::STATUS_PENDING_APPROVAL], true)) {
            throw new FixedAssetException('Only an acquisition under review or approval can be returned for correction.');
        }

        return DB::transaction(function () use ($acquisition, $creatorId, $userId, $reason) {
            // Pending approval rows are closed as returned so they
            // never decide a resubmitted acquisition.
            FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                ->where('transaction_id', $acquisition->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->update(['decision' => FixedAssetApproval::DECISION_RETURNED, 'decided_at' => now(), 'comment' => $reason]);

            $acquisition->update(['status' => FixedAssetAcquisition::STATUS_RETURNED]);
            // The asset goes back to draft so it can be corrected
            // and resubmitted — without this, updateAsset() refuses
            // the edit ("Only a draft or returned asset can be
            // edited") and a returned acquisition can never move.
            $asset = $acquisition->asset;
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_DRAFT, $userId, 'Returned for correction');
            $this->assets->logAudit($creatorId, $acquisition->asset_id, 'fixed_asset_acquisition', $acquisition->id, 'returned_for_correction', $userId, null, null, $reason);

            return $acquisition->fresh();
        });
    }

    public function submitForApproval(FixedAssetAcquisition $acquisition, int $creatorId, int $userId): FixedAssetAcquisition
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if ($acquisition->status !== FixedAssetAcquisition::STATUS_PENDING_REVIEW) {
            throw new FixedAssetException('Only an acquisition pending review can be submitted for approval.');
        }

        $asset = $acquisition->asset;
        $levels = FixedAssetSettings::approvalLevels($creatorId, (string) $asset->amount_base);

        return DB::transaction(function () use ($acquisition, $asset, $levels, $creatorId, $userId) {
            foreach ($levels as $level) {
                $exists = FixedAssetApproval::where('created_by', $creatorId)
                    ->where('asset_id', $asset->id)
                    ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                    ->where('transaction_id', $acquisition->id)
                    ->where('level', (int) $level['level'])
                    ->where('decision', FixedAssetApproval::DECISION_PENDING)
                    ->exists();
                if (! $exists) {
                    $this->assets->requestApproval(
                        $asset,
                        FixedAssetApproval::TYPE_ACQUISITION,
                        $acquisition->id,
                        $userId,
                        (string) $asset->amount_base,
                        (int) $level['level']
                    );
                }
            }

            $acquisition->update(['status' => FixedAssetAcquisition::STATUS_PENDING_APPROVAL]);
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_PENDING_APPROVAL, $userId, 'Submitted for approval');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_acquisition', $acquisition->id, 'submitted_for_approval', $userId, null, null);

            return $acquisition->fresh();
        });
    }

    /**
     * Decide one approval level. Wraps the Phase 0 decision
     * (which already refuses self-approval) with the level-2
     * rule: the second level must be a DIFFERENT user than the
     * one who approved level 1. When every required level is
     * approved, the acquisition is approved.
     */
    public function decideAcquisitionApproval(FixedAssetApproval $approval, int $approverId, string $decision, ?string $comment = null): FixedAssetApproval
    {
        $this->assertAcquisitionCompany($this->acquisitionOf($approval), (int) $approval->created_by);

        if ((int) $approval->level === 2) {
            $levelOne = FixedAssetApproval::where('created_by', $approval->created_by)
                ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                ->where('transaction_id', $approval->transaction_id)
                ->where('level', 1)
                ->where('decision', FixedAssetApproval::DECISION_APPROVED)
                ->first();
            if ($levelOne && (int) $levelOne->approver_id === $approverId) {
                throw new FixedAssetException('The second approval level must be a different user than the first.');
            }
        }

        $decided = $this->assets->decideApproval($approval, $approverId, $decision, $comment);

        $acquisition = $this->acquisitionOf($approval);
        $creatorId = (int) $approval->created_by;

        if ($decision === FixedAssetApproval::DECISION_REJECTED) {
            DB::transaction(function () use ($acquisition, $decided) {
                $acquisition->update(['status' => FixedAssetAcquisition::STATUS_REJECTED]);
                $asset = $acquisition->asset;
                $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_DRAFT, (int) $decided->approver_id, 'Acquisition rejected');
            });

            return $decided->fresh();
        }

        if ($decision === FixedAssetApproval::DECISION_RETURNED) {
            $this->returnForCorrection($acquisition, $creatorId, $approverId, $comment);

            return $decided->fresh();
        }

        // Approved: advance only when EVERY required level is approved.
        $pending = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
            ->where('transaction_id', $acquisition->id)
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->exists();
        if (! $pending) {
            $rejected = FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                ->where('transaction_id', $acquisition->id)
                ->where('decision', FixedAssetApproval::DECISION_REJECTED)
                ->exists();
            if (! $rejected) {
                DB::transaction(function () use ($acquisition, $creatorId, $approverId) {
                    $acquisition->update(['status' => FixedAssetAcquisition::STATUS_APPROVED]);
                    $asset = $acquisition->asset;
                    $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_APPROVED, $approverId, 'Acquisition approved');
                });
            }
        }

        return $decided->fresh();
    }

    public function rejectAcquisition(FixedAssetAcquisition $acquisition, int $creatorId, int $userId, ?string $reason = null): FixedAssetAcquisition
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if (! in_array($acquisition->status, [FixedAssetAcquisition::STATUS_PENDING_REVIEW, FixedAssetAcquisition::STATUS_PENDING_APPROVAL], true)) {
            throw new FixedAssetException('Only an acquisition under review or approval can be rejected.');
        }

        return DB::transaction(function () use ($acquisition, $creatorId, $userId, $reason) {
            FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                ->where('transaction_id', $acquisition->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->update(['decision' => FixedAssetApproval::DECISION_REJECTED, 'decided_at' => now(), 'comment' => $reason]);

            $acquisition->update(['status' => FixedAssetAcquisition::STATUS_REJECTED]);
            $asset = $acquisition->asset;
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_DRAFT, $userId, 'Acquisition rejected');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_acquisition', $acquisition->id, 'rejected', $userId, null, null, $reason);

            return $acquisition->fresh();
        });
    }

    public function cancelAcquisition(FixedAssetAcquisition $acquisition, int $creatorId, int $userId, ?string $reason = null): FixedAssetAcquisition
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if (in_array($acquisition->status, [FixedAssetAcquisition::STATUS_CAPITALIZED, FixedAssetAcquisition::STATUS_CANCELLED], true)) {
            throw new FixedAssetException('A capitalized or cancelled acquisition cannot be cancelled.');
        }

        return DB::transaction(function () use ($acquisition, $creatorId, $userId, $reason) {
            FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
                ->where('transaction_id', $acquisition->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->update(['decision' => FixedAssetApproval::DECISION_REJECTED, 'decided_at' => now(), 'comment' => $reason]);

            $acquisition->update(['status' => FixedAssetAcquisition::STATUS_CANCELLED]);
            $asset = $acquisition->asset;
            $this->assets->recordStatusChange($asset, $asset->status, FixedAsset::STATUS_INACTIVE, $userId, 'Acquisition cancelled');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_acquisition', $acquisition->id, 'cancelled', $userId, null, null, $reason);

            return $acquisition->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Capitalization (§9) — journal + schedule activation
    // ------------------------------------------------------------------

    /**
     * Capitalize an approved acquisition.
     *
     * Dr Fixed Asset account (category default, else class 32) /
     * Cr supplier AP (421) when a supplier invoice is linked, else
     * the per-company asset clearing account (D7). When the
     * clearing account is not configured and the acquisition
     * needs it, capitalization is refused with a message naming
     * the missing setting — no account is ever guessed.
     */
    public function capitalize(FixedAssetAcquisition $acquisition, int $creatorId, int $userId, ?string $date = null): FixedAssetCapitalization
    {
        $this->assertAcquisitionCompany($acquisition, $creatorId);

        if ($acquisition->status !== FixedAssetAcquisition::STATUS_APPROVED) {
            throw new FixedAssetException('Only an approved acquisition can be capitalized.');
        }

        $asset = $acquisition->asset;
        if (FixedAssetCapitalization::where('created_by', $creatorId)->where('asset_id', $asset->id)->exists()) {
            throw new FixedAssetException('This asset has already been capitalized.');
        }

        // Carbon's now(), not PHP date(): the app timezone applies
        // and the date is freezable in tests.
        $date = $date ?: now()->format('Y-m-d');
        $year = (int) date('Y', strtotime($date));
        if ($this->isFiscalYearClosed($creatorId, $year)) {
            throw new FixedAssetException('Fiscal year '.$year.' is already closed. Capitalization is blocked for closed periods.');
        }

        $capitalizedBase = (string) $asset->amount_base;
        if (bccomp($capitalizedBase, '0', 4) < 0) {
            throw new FixedAssetException('Capitalized cost must not be negative.');
        }

        $assetAccountId = $this->resolveAssetAccount($asset, $creatorId);
        $creditAccountId = $this->resolveCreditAccount($acquisition, $asset, $creatorId);

        return DB::transaction(function () use ($acquisition, $asset, $creatorId, $userId, $date, $capitalizedBase, $assetAccountId, $creditAccountId) {
            $journal = null;
            if (bccomp($capitalizedBase, '0', 4) > 0) {
                $amount2dp = number_format((float) $capitalizedBase, 2, '.', '');
                $journal = $this->poster->post(
                    $creatorId,
                    $date,
                    $asset->code,
                    'Capitalization of fixed asset '.$asset->code.' ('.$asset->name.')',
                    [
                        ['account' => $assetAccountId, 'debit' => (float) $amount2dp, 'description' => 'Capitalization of fixed asset '.$asset->code],
                        ['account' => $creditAccountId, 'credit' => (float) $amount2dp, 'description' => 'Capitalization of fixed asset '.$asset->code],
                    ]
                );
            }

            $capitalization = FixedAssetCapitalization::create([
                'created_by' => $creatorId,
                'asset_id' => $asset->id,
                'acquisition_id' => $acquisition->id,
                'capitalization_date' => $date,
                'capitalized_cost' => $asset->capitalized_cost,
                'journal_id' => $journal ? $journal->id : null,
                'capitalized_by' => $userId,
            ]);

            if ($journal) {
                FixedAssetAccountingEntry::create([
                    'created_by' => $creatorId,
                    'asset_id' => $asset->id,
                    'transaction_type' => FixedAssetAccountingEntry::TYPE_CAPITALIZATION,
                    'transaction_id' => $capitalization->id,
                    'journal_id' => $journal->id,
                ]);
            }

            $residualBase = bcmul((string) ($asset->residual_value ?? '0'), (string) $asset->exchange_rate, 4);
            $bookValue = bcsub($capitalizedBase, (string) ($asset->opening_accumulated_depreciation ?? '0'), 4);
            // Fully depreciated only when the asset actually had a
            // cost to depreciate: a zero-cost donation has book
            // value 0 = residual 0 but was never depreciated, so it
            // is available, not fully depreciated.
            $newStatus = bccomp($capitalizedBase, '0', 4) > 0 && bccomp($bookValue, $residualBase, 4) <= 0
                ? FixedAsset::STATUS_FULLY_DEPRECIATED
                : FixedAsset::STATUS_AVAILABLE;

            $asset->update([
                'capitalization_date' => $date,
                'depreciation_start_date' => $asset->depreciation_start_date ?: $date,
                'current_book_value' => $bookValue,
                'status' => $newStatus,
            ]);

            $this->generateSchedule($asset->fresh(), $creatorId);

            $acquisition->update([
                'status' => FixedAssetAcquisition::STATUS_CAPITALIZED,
                'capitalized_at' => now(),
                'journal_id' => $journal ? $journal->id : null,
            ]);

            $this->assets->recordStatusChange($asset->fresh(), FixedAsset::STATUS_APPROVED, $newStatus, $userId, 'Capitalized');
            $this->assets->logAudit($creatorId, $asset->id, 'fixed_asset_capitalization', $capitalization->id, 'capitalized', $userId, null, $capitalization->fresh()->toArray());

            return $capitalization->fresh();
        });
    }

    /** Asset account: the asset's override, else the category default, else class 32. */
    protected function resolveAssetAccount(FixedAsset $asset, int $creatorId): int
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
     * Credit side: supplier AP (421) when a supplier invoice is
     * linked, else the per-company asset clearing account (D7).
     */
    protected function resolveCreditAccount(FixedAssetAcquisition $acquisition, FixedAsset $asset, int $creatorId): int
    {
        $invoiceLinked = $acquisition->source_type === FixedAssetAcquisition::SOURCE_BILL_PRODUCT
            || ($acquisition->invoice_reference ?? $asset->invoice_reference ?? '') !== '';

        if ($invoiceLinked) {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '421');
            if ($id) {
                return (int) $id;
            }

            throw new FixedAssetException('No accounts payable account (421) exists for this company. Create one before capitalizing an invoice-linked acquisition.');
        }

        $code = trim((string) (FixedAssetSettings::get($creatorId, FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE) ?? ''));
        if ($code === '') {
            throw new FixedAssetException('Asset clearing account is not configured. Set the "Asset clearing account code" in Fixed Assets → Settings before capitalizing a direct, donation or opening-balance acquisition.');
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('Asset clearing account "'.$code.'" does not exist in the chart of accounts. Fix the "Asset clearing account code" in Fixed Assets → Settings.');
    }

    /** A fiscal year is closed once its FY-{year}-CLOSE journals exist (same rule as the year-end closing service). */
    public function isFiscalYearClosed(int $creatorId, int $year): bool
    {
        return JournalEntry::where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-'.$year.'-CLOSE%')
            ->exists();
    }

    // ------------------------------------------------------------------
    // Depreciation schedule shell (spec §13, §14) — the plan the
    // Phase 3 engine posts against. Generated at capitalization.
    // ------------------------------------------------------------------

    /** Useful life in months: year → ×12, month → as-is, week → weeks×7 days rounded to months. */
    public function monthsForLife(?int $life, string $unit): int
    {
        if ($life === null || $life <= 0) {
            return 0;
        }

        return match ($unit) {
            'year' => $life * 12,
            'month' => $life,
            'week' => max(1, (int) round($life * 7 / 30.4375)),
            default => $life * 12,
        };
    }

    /**
     * Generate the monthly schedule rows for an asset. The
     * depreciable base is (capitalized base − residual base −
     * opening accumulated depreciation); the rounding residual
     * lands on the final period so the last closing book value
     * is exactly the residual value.
     *
     * @return array<int, FixedAssetDepreciationSchedule>
     */
    public function generateSchedule(FixedAsset $asset, int $creatorId): array
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        $months = $this->monthsForLife($asset->useful_life, (string) ($asset->life_unit ?: 'year'));
        if ($months <= 0 || ! $asset->depreciation_start_date) {
            return [];
        }

        $residualBase = bcmul((string) ($asset->residual_value ?? '0'), (string) $asset->exchange_rate, 4);
        $openingAccum = (string) ($asset->opening_accumulated_depreciation ?? '0');
        $depreciable = bcsub(bcsub((string) $asset->amount_base, $residualBase, 4), $openingAccum, 4);
        if (bccomp($depreciable, '0', 4) <= 0) {
            return [];
        }

        $monthly = bcdiv($depreciable, (string) $months, 4);
        $start = (clone $asset->depreciation_start_date)->startOfMonth();

        return DB::transaction(function () use ($asset, $creatorId, $months, $monthly, $depreciable, $residualBase, $openingAccum, $start) {
            FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->delete();

            $rows = [];
            $opening = bcsub((string) $asset->amount_base, $openingAccum, 4);
            $accumulated = $openingAccum;
            $period = $start->copy();

            for ($n = 1; $n <= $months; $n++) {
                $isLast = $n === $months;
                // Last period absorbs the rounding residual so the
                // total is exact and the final book value is the
                // residual value.
                $dep = $isLast
                    ? bcsub($depreciable, bcmul($monthly, (string) ($months - 1), 4), 4)
                    : $monthly;
                $closing = bcsub($opening, $dep, 4);
                $accumulated = bcadd($accumulated, $dep, 4);

                $rows[] = FixedAssetDepreciationSchedule::create([
                    'created_by' => $creatorId,
                    'asset_id' => $asset->id,
                    'period' => $period->format('Y-m-d'),
                    'period_number' => $n,
                    'opening_book_value' => $opening,
                    'period_depreciation' => $dep,
                    'accumulated_depreciation' => $accumulated,
                    'closing_book_value' => $closing,
                    'remaining_life_months' => $months - $n,
                    'status' => FixedAssetDepreciationSchedule::STATUS_PLANNED,
                ]);

                $opening = $closing;
                $period = $period->copy()->addMonth();
            }

            return $rows;
        });
    }

    // ------------------------------------------------------------------
    // Excel import of old assets (D4) — opening accumulated
    // depreciation + remaining life per asset
    // ------------------------------------------------------------------

    /**
     * Import opening-balance assets from heading-keyed rows
     * (see FixedAssetImport). Each valid row creates a DRAFT
     * asset + draft acquisition; the normal review → approval →
     * capitalization flow capitalizes it. Invalid rows are
     * skipped and reported — one bad row never blocks the rest.
     *
     * @return array{created: int, skipped: int, errors: array<int, string>}
     */
    public function importRows(array $rows, int $creatorId, int $userId): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 header, +1 zero-based index
            try {
                $this->importRow($row, $creatorId, $userId);
                $created++;
            } catch (FixedAssetException $e) {
                $skipped++;
                $errors[$rowNumber] = $e->getMessage();
            } catch (\Throwable $e) {
                // A non-numeric cell (e.g. text in purchase_cost)
                // raises a ValueError inside bcmath — still just a
                // bad row, never a reason to abort the whole import.
                $skipped++;
                $errors[$rowNumber] = 'Row could not be imported: '.$e->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    protected function importRow(array $row, int $creatorId, int $userId): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new FixedAssetException('Name is required.');
        }

        $categoryCode = strtoupper(trim((string) ($row['category_code'] ?? '')));
        if ($categoryCode === '') {
            throw new FixedAssetException('Category code is required.');
        }
        $category = FixedAssetCategory::where('created_by', $creatorId)
            ->where('code', $categoryCode)
            ->first();
        if (! $category) {
            throw new FixedAssetException("Category '{$categoryCode}' does not exist for this company.");
        }

        $purchaseCost = $this->dec($row['purchase_cost'] ?? '0');
        if (bccomp($purchaseCost, '0', 4) < 0) {
            throw new FixedAssetException('Purchase cost must not be negative.');
        }

        $currency = strtoupper(trim((string) ($row['currency'] ?? ''))) ?: FixedAssetSettings::baseCurrency($creatorId);
        $rate = $this->rate($row['exchange_rate'] ?? '1');

        $locationId = null;
        $locationCode = strtoupper(trim((string) ($row['location_code'] ?? '')));
        if ($locationCode !== '') {
            $location = \App\Models\FixedAssetLocation::where('created_by', $creatorId)->where('code', $locationCode)->first();
            if (! $location) {
                throw new FixedAssetException("Location '{$locationCode}' does not exist for this company.");
            }
            $locationId = (int) $location->id;
        }

        $costCentreId = null;
        $costCentreCode = strtoupper(trim((string) ($row['cost_centre_code'] ?? '')));
        if ($costCentreCode !== '') {
            $costCentre = \App\Models\CostCentre::where('created_by', $creatorId)->where('code', $costCentreCode)->first();
            if (! $costCentre) {
                throw new FixedAssetException("Cost centre '{$costCentreCode}' does not exist for this company.");
            }
            $costCentreId = (int) $costCentre->id;
        }

        $method = strtolower(trim((string) ($row['acquisition_method'] ?? 'opening_balance')));
        if (! in_array($method, FixedAsset::ACQUISITION_METHODS, true)) {
            throw new FixedAssetException("Acquisition method '{$method}' is not valid.");
        }

        $openingAccum = $this->dec($row['opening_accumulated_depreciation'] ?? '0');
        if (bccomp($openingAccum, '0', 4) < 0) {
            throw new FixedAssetException('Opening accumulated depreciation must not be negative.');
        }

        $life = $row['remaining_life'] ?? null;
        $lifeUnit = strtolower(trim((string) ($row['remaining_life_unit'] ?? '')));
        if (! in_array($lifeUnit, ['week', 'month', 'year'], true)) {
            $lifeUnit = (string) ($category->default_life_unit ?: 'year');
        }

        $components = [[
            'component_type' => FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE,
            'gross_amount' => $purchaseCost,
            'vat_amount' => $this->dec($row['vat_amount'] ?? '0'),
            'vat_recoverable' => filter_var($row['vat_recoverable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'source_document_reference' => trim((string) ($row['source_document_reference'] ?? '')) ?: null,
        ]];
        foreach ([
            'transport' => FixedAssetAcquisitionCost::TYPE_TRANSPORT,
            'installation' => FixedAssetAcquisitionCost::TYPE_INSTALLATION,
            'import_duties' => FixedAssetAcquisitionCost::TYPE_IMPORT_DUTIES,
            'non_recoverable_taxes' => FixedAssetAcquisitionCost::TYPE_NON_RECOVERABLE_TAXES,
            'professional_fees' => FixedAssetAcquisitionCost::TYPE_PROFESSIONAL_FEES,
            'directly_attributable' => FixedAssetAcquisitionCost::TYPE_DIRECTLY_ATTRIBUTABLE,
            'discounts' => FixedAssetAcquisitionCost::TYPE_DISCOUNT,
        ] as $key => $type) {
            $amount = $this->dec($row[$key] ?? '0');
            if (bccomp($amount, '0', 4) > 0) {
                $components[] = [
                    'component_type' => $type,
                    'gross_amount' => $amount,
                    'vat_amount' => '0.0000',
                    'vat_recoverable' => false,
                ];
            }
        }

        $this->createAsset([
            'category_id' => (int) $category->id,
            'location_id' => $locationId,
            'cost_centre_id' => $costCentreId,
            'name' => $name,
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'serial_number' => trim((string) ($row['serial_number'] ?? '')) ?: null,
            'brand' => trim((string) ($row['brand'] ?? '')) ?: null,
            'model' => trim((string) ($row['model'] ?? '')) ?: null,
            'supplier' => trim((string) ($row['supplier'] ?? '')) ?: null,
            'acquisition_method' => $method,
            'purchase_date' => $this->dateOrNull($row['purchase_date'] ?? null),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'components' => $components,
            'useful_life' => $life !== null && $life !== '' ? (int) $life : $category->default_useful_life,
            'life_unit' => $lifeUnit,
            'residual_value' => $this->dec($row['residual_value'] ?? '0'),
            'opening_accumulated_depreciation' => $openingAccum,
            'warranty_start' => $this->dateOrNull($row['warranty_start'] ?? null),
            'warranty_end' => $this->dateOrNull($row['warranty_end'] ?? null),
        ], $creatorId, $userId);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function latestAcquisition(FixedAsset $asset, int $creatorId): ?FixedAssetAcquisition
    {
        return FixedAssetAcquisition::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->orderByDesc('id')
            ->first();
    }

    protected function acquisitionOf(FixedAssetApproval $approval): FixedAssetAcquisition
    {
        $acquisition = FixedAssetAcquisition::find((int) $approval->transaction_id);
        if (! $acquisition) {
            throw new FixedAssetException('Acquisition not found.');
        }

        return $acquisition;
    }

    protected function assertAcquisitionCompany(FixedAssetAcquisition $acquisition, int $creatorId): void
    {
        if ((int) $acquisition->created_by !== $creatorId) {
            throw new FixedAssetException('Record not found for this company.');
        }
    }

    /**
     * Split the built cost into $count equal shares: each share
     * is truncated to 4dp, the last share takes the remainder so
     * the shares always sum to the exact total (the "10
     * computers from 1 PO line" case, spec §6).
     */
    protected function splitCost(array $cost, int $count, int $index): array
    {
        if ($count <= 1) {
            return $cost + ['purchase_cost' => $this->purchasePriceNet($cost['lines'])];
        }

        $split = function (string $total) use ($count, $index): string {
            $share = bcdiv($total, (string) $count, 4);
            if ($index === $count) {
                return bcsub($total, bcmul($share, (string) ($count - 1), 4), 4);
            }

            return $share;
        };

        $lines = [];
        foreach ($cost['lines'] as $line) {
            $lines[] = array_merge($line, [
                'gross_amount' => $split($line['gross_amount']),
                'vat_amount' => $split($line['vat_amount']),
                'amount_base' => $split($line['amount_base']),
            ]);
        }

        return [
            'lines' => $lines,
            'capitalized_cost' => $split($cost['capitalized_cost']),
            'capitalized_base' => $split($cost['capitalized_base']),
            'purchase_cost' => $this->purchasePriceNet($lines),
        ];
    }

    protected function purchasePriceNet(array $lines): string
    {
        foreach ($lines as $line) {
            if ($line['component_type'] === FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE) {
                $net = bcsub($line['gross_amount'], $line['vat_recoverable'] ? $line['vat_amount'] : '0', 4);

                return $net;
            }
        }

        return '0.0000';
    }

    /**
     * Validate + normalize the New Asset form data into the asset
     * attributes, the acquisition attributes and the built cost.
     */
    protected function normalizeAssetData(array $data, int $creatorId, ?FixedAsset $existing = null): array
    {
        $categoryId = (int) ($data['category_id'] ?? 0);
        $category = FixedAssetCategory::where('created_by', $creatorId)->where('id', $categoryId)->first();
        if (! $category) {
            throw new FixedAssetException('Category not found for this company.');
        }
        if (! $category->isActive()) {
            throw new FixedAssetException('Category is not active.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new FixedAssetException('Asset name is required.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: FixedAssetSettings::baseCurrency($creatorId);
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new FixedAssetException('Currency must be a 3-letter code.');
        }
        $exchangeRate = $this->rate($data['exchange_rate'] ?? '1');
        if ($currency === FixedAssetSettings::baseCurrency($creatorId)) {
            $exchangeRate = '1.0000000000';
        }

        $method = (string) ($data['acquisition_method'] ?? FixedAsset::ACQUISITION_PURCHASE);
        if (! in_array($method, FixedAsset::ACQUISITION_METHODS, true)) {
            throw new FixedAssetException('Invalid acquisition method.');
        }

        $sourceType = (string) ($data['source_type'] ?? FixedAssetAcquisition::SOURCE_DIRECT);
        if (! in_array($sourceType, FixedAssetAcquisition::SOURCE_TYPES, true)) {
            throw new FixedAssetException('Invalid acquisition source type.');
        }
        $sourceId = isset($data['source_id']) && $data['source_id'] !== '' ? (int) $data['source_id'] : null;

        $this->assertBelongsToCompany('branches', (int) ($data['branch_id'] ?? 0), $creatorId, 'Branch');
        $this->assertBelongsToCompany('departments', (int) ($data['department_id'] ?? 0), $creatorId, 'Department');
        $this->assertBelongsToCompany('cost_centres', (int) ($data['cost_centre_id'] ?? 0), $creatorId, 'Cost centre');
        $this->assertBelongsToCompany('fixed_asset_locations', (int) ($data['location_id'] ?? 0), $creatorId, 'Location');
        $this->assertBelongsToCompany('employees', (int) ($data['custodian_id'] ?? 0), $creatorId, 'Custodian');

        $parentId = isset($data['parent_asset_id']) && $data['parent_asset_id'] !== '' ? (int) $data['parent_asset_id'] : null;
        if ($parentId !== null) {
            $parent = FixedAsset::where('created_by', $creatorId)->where('id', $parentId)->first();
            if (! $parent) {
                throw new FixedAssetException('Parent asset not found for this company.');
            }
            if ($existing && $parent->id === $existing->id) {
                throw new FixedAssetException('An asset cannot be its own parent.');
            }
        }

        $components = $data['components'] ?? [];
        if (! is_array($components)) {
            throw new FixedAssetException('Cost components are invalid.');
        }
        $cost = $this->buildCost($components, $currency, $exchangeRate);

        $methodCapitalized = $cost['capitalized_cost'];
        if (bccomp($methodCapitalized, '0', 4) < 0) {
            throw new FixedAssetException('Capitalized cost must not be negative.');
        }
        if (bccomp($methodCapitalized, '0', 4) === 0 && $method !== FixedAsset::ACQUISITION_DONATION) {
            throw new FixedAssetException('Capitalized cost must be greater than zero.');
        }

        $lifeUnit = (string) ($data['life_unit'] ?? $category->default_life_unit ?: 'year');
        if (! in_array($lifeUnit, FixedAssetCategory::LIFE_UNITS, true)) {
            throw new FixedAssetException('Invalid useful life unit.');
        }
        $life = $data['useful_life'] ?? $category->default_useful_life;
        $life = $life === '' || $life === null ? null : (int) $life;
        if ($life !== null && $life <= 0) {
            throw new FixedAssetException('Useful life must be a positive number.');
        }

        $residualType = (string) ($category->residual_value_type ?: FixedAssetCategory::RESIDUAL_NONE);
        $residual = $this->dec($data['residual_value'] ?? '0');
        if ($residualType === FixedAssetCategory::RESIDUAL_PERCENTAGE && $category->residual_value_percent !== null) {
            $residual = bcmul($methodCapitalized, bcdiv((string) $category->residual_value_percent, '100', 10), 4);
        } elseif ($residualType === FixedAssetCategory::RESIDUAL_FIXED && $category->residual_value !== null) {
            $residual = $this->dec((string) $category->residual_value);
        }
        if (bccomp($residual, $methodCapitalized, 4) > 0) {
            throw new FixedAssetException('Residual value must not exceed the capitalized cost.');
        }

        $openingAccum = $this->dec($data['opening_accumulated_depreciation'] ?? '0');
        if (bccomp($openingAccum, '0', 4) < 0) {
            throw new FixedAssetException('Opening accumulated depreciation must not be negative.');
        }
        if (bccomp($openingAccum, $cost['capitalized_base'], 4) > 0) {
            throw new FixedAssetException('Opening accumulated depreciation must not exceed the capitalized cost.');
        }

        $assetAccountId = isset($data['asset_account_id']) && $data['asset_account_id'] !== '' ? (int) $data['asset_account_id'] : null;
        if ($assetAccountId !== null) {
            $this->assertChartAccount($assetAccountId, $creatorId);
        }
        $accumId = isset($data['accum_depreciation_account_id']) && $data['accum_depreciation_account_id'] !== '' ? (int) $data['accum_depreciation_account_id'] : null;
        if ($accumId !== null) {
            $this->assertChartAccount($accumId, $creatorId);
        }
        $expenseId = isset($data['depreciation_expense_account_id']) && $data['depreciation_expense_account_id'] !== '' ? (int) $data['depreciation_expense_account_id'] : null;
        if ($expenseId !== null) {
            $this->assertChartAccount($expenseId, $creatorId);
        }

        return [
            'category' => $category,
            'asset' => [
                'category_id' => $category->id,
                'branch_id' => isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null,
                'department_id' => isset($data['department_id']) && $data['department_id'] !== '' ? (int) $data['department_id'] : null,
                'cost_centre_id' => isset($data['cost_centre_id']) && $data['cost_centre_id'] !== '' ? (int) $data['cost_centre_id'] : null,
                'location_id' => isset($data['location_id']) && $data['location_id'] !== '' ? (int) $data['location_id'] : null,
                'custodian_id' => isset($data['custodian_id']) && $data['custodian_id'] !== '' ? (int) $data['custodian_id'] : null,
                'parent_asset_id' => $parentId,
                'is_component' => $parentId !== null,
                'name' => $name,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'serial_number' => trim((string) ($data['serial_number'] ?? '')) ?: null,
                'model' => trim((string) ($data['model'] ?? '')) ?: null,
                'brand' => trim((string) ($data['brand'] ?? '')) ?: null,
                'barcode_value' => trim((string) ($data['barcode_value'] ?? '')) ?: null,
                'acquisition_method' => $method,
                'supplier' => trim((string) ($data['supplier'] ?? '')) ?: null,
                'po_reference' => trim((string) ($data['po_reference'] ?? '')) ?: null,
                'invoice_reference' => trim((string) ($data['invoice_reference'] ?? '')) ?: null,
                'purchase_date' => $this->dateOrNull($data['purchase_date'] ?? null),
                'receipt_date' => $this->dateOrNull($data['receipt_date'] ?? null),
                'purchase_cost' => $this->purchasePriceNet($cost['lines']),
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount_base' => $cost['capitalized_base'],
                'capitalized_cost' => $cost['capitalized_cost'],
                'warranty_start' => $this->dateOrNull($data['warranty_start'] ?? null),
                'warranty_end' => $this->dateOrNull($data['warranty_end'] ?? null),
                'depreciation_start_date' => $this->dateOrNull($data['depreciation_start_date'] ?? null),
                'useful_life' => $life,
                'life_unit' => $lifeUnit,
                'depreciation_method' => 'straight_line',
                'residual_value' => $residual,
                'asset_account_id' => $assetAccountId ?? $category->asset_account_id,
                'accum_depreciation_account_id' => $accumId ?? $category->accum_depreciation_account_id,
                'depreciation_expense_account_id' => $expenseId ?? $category->depreciation_expense_account_id,
                'opening_accumulated_depreciation' => $openingAccum,
                'status' => FixedAsset::STATUS_DRAFT,
                'current_book_value' => '0.0000',
            ],
            'acquisition' => [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_reference' => trim((string) ($data['source_reference'] ?? '')) ?: null,
                'supplier' => trim((string) ($data['supplier'] ?? '')) ?: null,
                'purchase_date' => $this->dateOrNull($data['purchase_date'] ?? null),
                'receipt_date' => $this->dateOrNull($data['receipt_date'] ?? null),
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ],
            'cost' => $cost,
        ];
    }

    protected function assertBelongsToCompany(string $table, int $id, int $creatorId, string $label): void
    {
        if ($id <= 0) {
            return;
        }
        if (! DB::table($table)->where('created_by', $creatorId)->where('id', $id)->exists()) {
            throw new FixedAssetException($label.' not found for this company.');
        }
    }

    protected function assertChartAccount(int $id, int $creatorId): void
    {
        if (! ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            throw new FixedAssetException('Chart account not found for this company.');
        }
    }

    public function dec(string $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    protected function rate(mixed $value): string
    {
        $rate = is_numeric($value) ? (string) $value : '1';
        if (bccomp($rate, '0', 10) <= 0) {
            throw new FixedAssetException('Exchange rate must be greater than zero.');
        }

        return bcadd($rate, '0', 10);
    }

    protected function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            throw new FixedAssetException('Invalid date.');
        }

        return date('Y-m-d', $ts);
    }
}
