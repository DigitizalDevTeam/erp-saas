<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\ChartOfAccount;
use App\Models\KitchenLoss;
use App\Models\KitchenPosBatchConsumption;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalEntryPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Kitchen Production — losses + internal consumption (Phase 5, plan §6).
 *
 * A kitchen loss is food that leaves a batch or the warehouse without
 * being sold: preparation loss, burnt / spoiled, buffet leftover,
 * customer return, staff meal, complimentary, tasting, or a transfer
 * between kitchens. Each loss has a category, a quantity, a cost, a
 * reason and a responsible person, and links to a production order, a
 * POS order and/or a batch where applicable, so it stays traceable.
 *
 * Flow: RECORDED -> APPROVED (where the category needs it) -> POSTED.
 * Food given away (staff meal, complimentary, tasting) needs a
 * manager's approval; routine operational waste is recorded directly
 * as approved. Approval is enforced here, not only in the blade, and
 * nobody approves a loss they recorded themselves.
 *
 * Posting is the only step that moves stock, and it is idempotent
 * (a posted loss can never be posted twice):
 * - Batch / warehouse loss: the batch is consumed (produced - sold -
 *   lost = remaining) and the warehouse stock falls, then the stock
 *   write-off goes through the existing WarehouseLoss model — the
 *   same stock movement the WarehouseLoss screen makes, including
 *   the Breakage / Inventory journal when the chart has the accounts.
 * - Transfer between kitchens: stock moves from one kitchen warehouse
 *   to another (a WarehouseTransfer row); nothing is written off.
 * - POS cancellation loss: the stock was already deducted when the
 *   line was sent to production, so posting only creates the
 *   WarehouseLoss log row and must not move stock a second time.
 *
 * Reuse: a loss can be flagged as reusable (still safe) with a
 * food-safety note and a destination. The destination can never be a
 * batch that is already sold — it is one of staff meal /
 * complimentary / re-prepped / other, never a batch reference.
 *
 * Staff meals and complimentary meals ordered through the POS keep
 * the existing 'internal' payment type (PosPayment::METHOD_INTERNAL)
 * — this service never touches the POS checkout. Production-side
 * internal consumption (a tasting, a staff meal taken from a batch)
 * is recorded here as a kitchen loss, not as a sale.
 *
 * Everything here is scoped by creatorId() (one company) and works
 * globally for all companies.
 */
class KitchenLossService
{
    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenProductionOrderService $orders,
    ) {
    }

    // -----------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------

    /**
     * Record a loss. Categories that need approval start as RECORDED
     * (pending a manager); the rest are recorded directly as APPROVED.
     * Nothing moves stock yet — posting does that.
     *
     * @param array{
     *   category: string, product_id: int, quantity: float|int,
     *   unit_id?: int|null, unit_cost?: float|int|null,
     *   warehouse_id: int, destination_warehouse_id?: int|null,
     *   reason?: string|null,
     *   reuse_flag?: bool, food_safety_note?: string|null,
     *   reuse_destination?: string|null,
     *   kitchen_production_order_id?: int|null,
     *   pos_id?: int|null, pos_product_id?: int|null,
     *   kitchen_production_batch_id?: int|null,
     *   stock_already_deducted?: bool
     * } $data
     */
    public function record(array $data, User $user): KitchenLoss
    {
        $creatorId = (int) $user->creatorId();
        $validated = $this->validateLossData($data, $creatorId);

        return DB::transaction(function () use ($validated, $data, $user, $creatorId) {
            return KitchenLoss::create([
                'loss_number' => $this->nextLossNumber($creatorId),
                'category' => $validated['category'],
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'unit_id' => $validated['unit_id'],
                'unit_cost' => $validated['unit_cost'],
                'total_cost' => $validated['total_cost'],
                'warehouse_id' => $validated['warehouse_id'],
                'destination_warehouse_id' => $validated['destination_warehouse_id'],
                'reason' => $data['reason'] ?? null,
                'recorded_by' => (int) $user->id,
                'status' => KitchenLoss::categoryNeedsApproval($validated['category'])
                    ? KitchenLoss::STATUS_RECORDED
                    : KitchenLoss::STATUS_APPROVED,
                'reuse_flag' => $validated['reuse_flag'],
                'food_safety_note' => $validated['food_safety_note'],
                'reuse_destination' => $validated['reuse_destination'],
                'kitchen_production_order_id' => $validated['kitchen_production_order_id'],
                'pos_id' => $validated['pos_id'],
                'pos_product_id' => $validated['pos_product_id'],
                'kitchen_production_batch_id' => $validated['kitchen_production_batch_id'],
                'stock_already_deducted' => ! empty($data['stock_already_deducted']),
                'created_by' => $creatorId,
            ]);
        });
    }

    /**
     * Edit a recorded loss: the header is replaced and the cost and
     * availability are re-computed. Only a loss still waiting for
     * approval (or recorded directly, before posting) can be edited —
     * never an approved-for-posting change to a posted or rejected one.
     * Directly-recorded categories sit in APPROVED, so they can be
     * edited until posted.
     */
    public function updateDraft(KitchenLoss $loss, array $data, User $user): KitchenLoss
    {
        if (! $loss->isRecorded() && ! ($loss->isApproved() && ! $loss->needsApproval())) {
            throw new InvalidArgumentException(__('Only a recorded loss can be edited.'));
        }

        $creatorId = (int) $loss->created_by;
        $validated = $this->validateLossData($data, $creatorId);

        return DB::transaction(function () use ($loss, $validated, $data) {
            $loss->update([
                'category' => $validated['category'],
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'unit_id' => $validated['unit_id'],
                'unit_cost' => $validated['unit_cost'],
                'total_cost' => $validated['total_cost'],
                'warehouse_id' => $validated['warehouse_id'],
                'destination_warehouse_id' => $validated['destination_warehouse_id'],
                'reason' => $data['reason'] ?? null,
                'reuse_flag' => $validated['reuse_flag'],
                'food_safety_note' => $validated['food_safety_note'],
                'reuse_destination' => $validated['reuse_destination'],
                'kitchen_production_order_id' => $validated['kitchen_production_order_id'],
                'pos_id' => $validated['pos_id'],
                'pos_product_id' => $validated['pos_product_id'],
                'kitchen_production_batch_id' => $validated['kitchen_production_batch_id'],
            ]);

            return $loss->fresh();
        });
    }

    /**
     * Shared validation for record() and updateDraft(): resolves the
     * product, warehouse, batch, order and POS links (all must belong
     * to the company), converts the entered quantity to the product's
     * stock unit, computes the cost, and enforces the category rules
     * (transfer destination, reuse food-safety note, batch cover).
     *
     * @return array{category: string, product_id: int, quantity: float, unit_id: int|null, unit_cost: float, total_cost: float, warehouse_id: int, destination_warehouse_id: int|null, reuse_flag: bool, food_safety_note: string|null, reuse_destination: string|null, kitchen_production_order_id: int|null, pos_id: int|null, pos_product_id: int|null, kitchen_production_batch_id: int|null}
     */
    protected function validateLossData(array $data, int $creatorId): array
    {
        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, KitchenLoss::CATEGORIES, true)) {
            throw new InvalidArgumentException(__('Select a loss category.'));
        }

        $product = ProductService::where('id', (int) ($data['product_id'] ?? 0))
            ->where('created_by', $creatorId)
            ->first();
        if (! $product) {
            throw new InvalidArgumentException(__('The lost product does not exist.'));
        }

        $quantity = (float) ($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('The lost quantity must be greater than zero.'));
        }

        $warehouse = warehouse::where('id', (int) ($data['warehouse_id'] ?? 0))
            ->where('created_by', $creatorId)
            ->first();
        if (! $warehouse) {
            throw new InvalidArgumentException(__('The warehouse is required.'));
        }

        // The quantity is stored in the product's stock unit. When the
        // user entered another unit, convert it here (a missing
        // conversion blocks the loss, same as everywhere in the module).
        $stockUnitId = (int) ($product->unit_id ?? 0);
        $enteredUnitId = isset($data['unit_id']) && $data['unit_id'] !== '' ? (int) $data['unit_id'] : $stockUnitId;
        if ($stockUnitId > 0 && $enteredUnitId > 0 && $enteredUnitId !== $stockUnitId) {
            $quantity = $this->conversions->convert($quantity, $enteredUnitId, $stockUnitId, $creatorId, (int) $product->id);
        }
        $quantity = round($quantity, 4);
        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('The lost quantity must be greater than zero.'));
        }

        $batch = null;
        if (! empty($data['kitchen_production_batch_id'])) {
            $batch = KitchenProductionBatch::where('id', (int) $data['kitchen_production_batch_id'])
                ->where('created_by', $creatorId)
                ->first();
            if (! $batch) {
                throw new InvalidArgumentException(__('The batch does not exist.'));
            }
            if ((int) $batch->product_id !== (int) $product->id) {
                throw new InvalidArgumentException(__('The batch is for a different product.'));
            }
            if ((int) $batch->warehouse_id !== (int) $warehouse->id) {
                throw new InvalidArgumentException(__('The batch is in a different warehouse.'));
            }
            if (! $batch->unit_id || (int) $batch->unit_id !== $stockUnitId) {
                throw new InvalidArgumentException(__('The batch unit does not match the product stock unit.'));
            }
            if ($quantity > (float) $batch->quantity_remaining + 1e-9) {
                throw new InvalidArgumentException(__(
                    'Cannot lose :qty of :product: batch :batch only holds :remaining.',
                    ['qty' => $quantity, 'product' => $product->name ?? '#'.$product->id, 'batch' => $batch->batch_number, 'remaining' => (float) $batch->quantity_remaining],
                ));
            }
        }

        $orderId = null;
        if (! empty($data['kitchen_production_order_id'])) {
            if (! KitchenProductionOrder::where('id', (int) $data['kitchen_production_order_id'])
                ->where('created_by', $creatorId)
                ->exists()) {
                throw new InvalidArgumentException(__('The production order does not exist.'));
            }
            $orderId = (int) $data['kitchen_production_order_id'];
        }

        $posId = null;
        if (! empty($data['pos_id'])) {
            if (! Pos::where('id', (int) $data['pos_id'])->where('created_by', $creatorId)->exists()) {
                throw new InvalidArgumentException(__('The POS order does not exist.'));
            }
            $posId = (int) $data['pos_id'];
        }

        $posProductId = null;
        if (! empty($data['pos_product_id'])) {
            if (! PosProduct::where('id', (int) $data['pos_product_id'])->where('created_by', $creatorId)->exists()) {
                throw new InvalidArgumentException(__('The POS line does not exist.'));
            }
            $posProductId = (int) $data['pos_product_id'];
        }

        // A transfer moves stock between kitchens; it needs a different
        // destination warehouse and never links a batch (the batch
        // record stays in its warehouse — a batch transfer is out of
        // scope for v1).
        $destinationWarehouseId = null;
        if ($category === KitchenLoss::CATEGORY_TRANSFER) {
            $destinationWarehouseId = (int) ($data['destination_warehouse_id'] ?? 0);
            $destination = $destinationWarehouseId > 0
                ? warehouse::where('id', $destinationWarehouseId)->where('created_by', $creatorId)->first()
                : null;
            if (! $destination) {
                throw new InvalidArgumentException(__('A transfer needs a destination warehouse.'));
            }
            if ($destinationWarehouseId === (int) $warehouse->id) {
                throw new InvalidArgumentException(__('The destination warehouse must be different from the source warehouse.'));
            }
            if ($batch) {
                throw new InvalidArgumentException(__('A transfer cannot link a batch.'));
            }
            $available = $this->orders->warehouseQty((int) $product->id, (int) $warehouse->id, $creatorId);
            if ($quantity > $available + 1e-9 && ! $product->allowsNegativeStock()) {
                throw new InvalidArgumentException(__(
                    'Cannot transfer :qty of :product: the warehouse only holds :available.',
                    ['qty' => $quantity, 'product' => $product->name ?? '#'.$product->id, 'available' => $available],
                ));
            }
        }

        // Reuse: the food-safety note is mandatory, and the destination
        // must be one of the safe destinations (never a sold batch).
        $reuseFlag = ! empty($data['reuse_flag']);
        $foodSafetyNote = $data['food_safety_note'] ?? null;
        $reuseDestination = $data['reuse_destination'] ?? null;
        if ($reuseFlag) {
            if ($foodSafetyNote === null || trim((string) $foodSafetyNote) === '') {
                throw new InvalidArgumentException(__('A reused loss needs a food-safety note.'));
            }
            if (! in_array($reuseDestination, KitchenLoss::REUSE_DESTINATIONS, true)) {
                throw new InvalidArgumentException(__('Select where the reused food goes.'));
            }
        }

        $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' && $data['unit_cost'] !== null
            ? max(0, (float) $data['unit_cost'])
            : (float) ($batch ? ($batch->unit_cost ?? $product->purchase_price ?? 0) : ($product->purchase_price ?? 0));
        $unitCost = round($unitCost, 4);

        return [
            'category' => $category,
            'product_id' => (int) $product->id,
            'quantity' => $quantity,
            'unit_id' => $stockUnitId > 0 ? $stockUnitId : null,
            'unit_cost' => $unitCost,
            'total_cost' => round($quantity * $unitCost, 2),
            'warehouse_id' => (int) $warehouse->id,
            'destination_warehouse_id' => $destinationWarehouseId,
            'reuse_flag' => $reuseFlag,
            'food_safety_note' => $foodSafetyNote,
            'reuse_destination' => $reuseDestination,
            'kitchen_production_order_id' => $orderId,
            'pos_id' => $posId,
            'pos_product_id' => $posProductId,
            'kitchen_production_batch_id' => $batch ? (int) $batch->id : null,
        ];
    }



    /**
     * Production-side internal consumption: a tasting, a staff meal or
     * a complimentary portion taken from a batch. It is a kitchen loss,
     * not a sale — the POS checkout and its 'internal' payment type
     * are untouched. The batch is required (the food comes from
     * prepared stock), and the category must be one of the three
     * give-away categories, so it always needs a manager's approval.
     */
    public function recordInternalConsumption(array $data, User $user): KitchenLoss
    {
        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, [
            KitchenLoss::CATEGORY_STAFF_MEAL,
            KitchenLoss::CATEGORY_COMPLIMENTARY,
            KitchenLoss::CATEGORY_TASTING,
        ], true)) {
            throw new InvalidArgumentException(__('Internal consumption is a staff meal, a complimentary portion or a tasting.'));
        }

        if (empty($data['kitchen_production_batch_id'])) {
            throw new InvalidArgumentException(__('Internal consumption is taken from a prepared batch.'));
        }

        return $this->record($data, $user);
    }

    // -----------------------------------------------------------------
    // Approval / rejection
    // -----------------------------------------------------------------

    public function approve(KitchenLoss $loss, User $user): KitchenLoss
    {
        if (! $loss->isRecorded()) {
            throw new InvalidArgumentException(__('Only a recorded loss can be approved.'));
        }

        if (! $loss->needsApproval()) {
            throw new InvalidArgumentException(__('This category does not need approval — it is recorded directly as approved.'));
        }

        if ((int) $user->id === (int) $loss->recorded_by) {
            throw new InvalidArgumentException(__('You cannot approve a loss you recorded yourself.'));
        }

        $loss->update([
            'status' => KitchenLoss::STATUS_APPROVED,
            'approved_by' => (int) $user->id,
            'approved_at' => now(),
        ]);

        return $loss->fresh();
    }

    public function reject(KitchenLoss $loss, User $user, ?string $note = null): KitchenLoss
    {
        if (! $loss->isRecorded()) {
            throw new InvalidArgumentException(__('Only a recorded loss can be rejected.'));
        }

        if ((int) $user->id === (int) $loss->recorded_by) {
            throw new InvalidArgumentException(__('You cannot reject a loss you recorded yourself.'));
        }

        $loss->update([
            'status' => KitchenLoss::STATUS_REJECTED,
            'rejected_by' => (int) $user->id,
            'rejected_at' => now(),
            'rejection_note' => $note,
        ]);

        return $loss->fresh();
    }

    // -----------------------------------------------------------------
    // Posting (the only step that moves stock)
    // -----------------------------------------------------------------

    /**
     * Post an approved loss. Idempotent: a posted loss can never be
     * posted twice. The stock movement depends on what the loss is:
     * - transfer: stock moves source warehouse -> destination warehouse
     *   (a WarehouseTransfer row); nothing is written off.
     * - stock already deducted (POS cancellation): only the WarehouseLoss
     *   log row is created; the stock must not move a second time.
     * - otherwise: the batch is consumed and the warehouse stock falls,
     *   then the write-off goes through WarehouseLoss (same stock
     *   movement the WarehouseLoss screen makes).
     */
    public function post(KitchenLoss $loss, User $user): KitchenLoss
    {
        if (! $loss->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved loss can be posted.'));
        }

        // A buffet leftover is a record of food kept for the next day,
        // not a write-off (Phase 4): its stock stays on hand, so
        // posting it would deduct the same food twice.
        if ($loss->category === KitchenLoss::CATEGORY_BUFFET_LEFTOVER) {
            throw new InvalidArgumentException(__('A buffet leftover is never posted — it records food kept for reuse.'));
        }

        $creatorId = (int) $loss->created_by;

        return DB::transaction(function () use ($loss, $user, $creatorId) {
            $loss = $loss->fresh();
            if (! $loss->isApproved()) {
                throw new InvalidArgumentException(__('Only an approved loss can be posted.'));
            }

            $quantity = (float) $loss->quantity;
            $product = ProductService::where('id', (int) $loss->product_id)
                ->where('created_by', $creatorId)
                ->firstOrFail();

            if ($loss->category === KitchenLoss::CATEGORY_TRANSFER) {
                $this->postTransfer($loss, $product, $creatorId);
            } elseif ($loss->stock_already_deducted) {
                $writeOff = $this->createWriteOff($loss, $product, $creatorId, (int) $user->id);
                $loss->warehouse_loss_id = (int) $writeOff->id;
            } else {
                $this->checkWarehouseAvailability($loss, $product, $creatorId);

                if ($loss->kitchen_production_batch_id) {
                    $batch = KitchenProductionBatch::where('id', (int) $loss->kitchen_production_batch_id)
                        ->where('created_by', $creatorId)
                        ->lockForUpdate()
                        ->firstOrFail();
                    // Throws when the batch no longer holds enough
                    // (sales may have consumed it since recording).
                    $batch->consume($quantity);
                }

                Utility::warehouse_quantity('minus', $quantity, (int) $loss->product_id, (int) $loss->warehouse_id);
                Utility::total_quantity('minus', $quantity, (int) $loss->product_id);

                $writeOff = $this->createWriteOff($loss, $product, $creatorId, (int) $user->id);
                $loss->warehouse_loss_id = (int) $writeOff->id;

                Utility::addProductStock(
                    (int) $loss->product_id,
                    $quantity,
                    'warehouse_loss',
                    __('Stock loss/damage write-off'),
                    (int) $writeOff->id,
                    (int) $writeOff->id
                );
            }

            $loss->update([
                'status' => KitchenLoss::STATUS_POSTED,
                'posted_by' => (int) $user->id,
                'posted_at' => now(),
            ]);

            return $loss->fresh();
        });
    }

    /** Move the stock between two kitchen warehouses; nothing is written off. */
    protected function postTransfer(KitchenLoss $loss, ProductService $product, int $creatorId): void
    {
        $fromId = (int) $loss->warehouse_id;
        $toId = (int) $loss->destination_warehouse_id;
        $quantity = (float) $loss->quantity;

        $transfer = WarehouseTransfer::create([
            'transfer_batch_id' => null,
            'from_warehouse' => $fromId,
            'to_warehouse' => $toId,
            'product_id' => (int) $loss->product_id,
            'quantity' => $quantity,
            'date' => now()->format('Y-m-d'),
            'created_by' => $creatorId,
        ]);

        $toRow = WarehouseProduct::where('warehouse_id', $toId)
            ->where('product_id', (int) $loss->product_id)
            ->first();
        if (! $toRow) {
            $toRow = new WarehouseProduct();
            $toRow->warehouse_id = $toId;
            $toRow->product_id = (int) $loss->product_id;
            $toRow->quantity = 0;
            $toRow->created_by = $creatorId;
        }
        $toRow->quantity = (float) $toRow->quantity + $quantity;
        $toRow->save();

        $fromRow = WarehouseProduct::where('warehouse_id', $fromId)
            ->where('product_id', (int) $loss->product_id)
            ->first();
        if ($fromRow) {
            $fromRow->quantity = (float) $fromRow->quantity - $quantity;
            if ($fromRow->quantity <= 0) {
                $fromRow->delete();
            } else {
                $fromRow->save();
            }
        }

        $description = 'Kitchen loss '.$loss->loss_number.' (transfer between kitchens)';

        $this->recordStockReport((int) $loss->product_id, $quantity, 'transfer_out', $description, (int) $transfer->id, $creatorId);
        $this->recordStockReport((int) $loss->product_id, $quantity, 'transfer_in', $description, (int) $transfer->id, $creatorId);
    }

    /** Same row shape as Utility::addProductStock, company id explicit. */
    protected function recordStockReport(int $productId, float $quantity, string $type, string $description, int $typeId, int $creatorId): void
    {
        // created_by is NOT in StockReport::$fillable, so it must be
        // assigned as a property — passing it to create() would
        // silently drop it to 0.
        $report = new StockReport();
        $report->product_id = $productId;
        $report->quantity = $quantity;
        $report->type = $type;
        $report->type_id = $typeId;
        $report->warehouse_loss_id = null;
        $report->description = $description;
        $report->created_by = $creatorId;
        $report->save();
    }

    protected function checkWarehouseAvailability(KitchenLoss $loss, ProductService $product, int $creatorId): void
    {
        if ($product->allowsNegativeStock()) {
            return;
        }

        $available = $this->orders->warehouseQty((int) $loss->product_id, (int) $loss->warehouse_id, $creatorId);
        if ((float) $loss->quantity > $available + 1e-9) {
            throw new InvalidArgumentException(__(
                'Cannot post a loss of :qty of :product: the warehouse only holds :available.',
                ['qty' => (float) $loss->quantity, 'product' => $product->name ?? '#'.$product->id, 'available' => $available],
            ));
        }
    }

    /** The stock write-off row (WarehouseLoss) for a posted loss. */
    protected function createWriteOff(KitchenLoss $loss, ProductService $product, int $creatorId, int $userId): WarehouseLoss
    {
        $user = User::find($userId);

        $writeOff = WarehouseLoss::create([
            'write_off_no' => $this->nextWriteOffNumber($creatorId),
            'warehouse_id' => (int) $loss->warehouse_id,
            'product_id' => (int) $loss->product_id,
            'quantity' => (float) $loss->quantity,
            'unit_cost' => (float) ($loss->unit_cost ?? 0),
            'cost_of_goods' => (float) $loss->total_cost,
            'date' => date('Y-m-d'),
            'reason' => __('Kitchen loss :number (:category)', ['number' => $loss->loss_number, 'category' => $loss->category])
                . ($loss->reason ? ' — '.$loss->reason : ''),
            'issuer_name' => $user ? (string) ($user->name ?? '') : '',
            'authorized_by' => $loss->approved_by ? (string) (User::find($loss->approved_by)->name ?? '') : ($user ? (string) ($user->name ?? '') : ''),
            'witness_name' => null,
            'delivery_note_required' => false,
            'delivery_destination' => $loss->reuse_flag ? (string) ($loss->reuse_destination ?? '') : null,
            'created_by' => $creatorId,
        ]);

        $this->postWriteOffJournal($creatorId, date('Y-m-d'), (string) $writeOff->write_off_no, (float) $loss->total_cost, (string) ($loss->reason ?? ''));

        return $writeOff;
    }

    protected function nextWriteOffNumber(int $creatorId): string
    {
        $maxId = (int) (WarehouseLoss::where('created_by', $creatorId)->max('id') ?? 0);

        return 'WO-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Debit Breakage (5025) / Credit Inventory (1510), mirroring
     * WarehouseLossController::postLossJournal. Guarded: when the
     * chart has no such accounts (or the accounting tables do not
     * exist in a minimal test schema) the journal is skipped with a
     * warning — a missing chart must never block posting a loss.
     */
    protected function postWriteOffJournal(int $creatorId, string $date, string $writeOffNo, float $totalCost, string $reason): void
    {
        if ($totalCost <= 0 || ! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $expenseAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '5025')->orderBy('id')->first();
        $inventoryAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '1510')->orderBy('id')->first();

        if (! $expenseAccount || ! $inventoryAccount) {
            Log::warning('Kitchen loss journal skipped: missing chart accounts 5025/1510', [
                'created_by' => $creatorId,
                'write_off_no' => $writeOffNo,
            ]);

            return;
        }

        $description = trim(__('Kitchen loss write-off') . ' ' . $writeOffNo . ($reason !== '' ? ' — ' . $reason : ''));

        try {
            app(JournalEntryPoster::class)->post(
                $creatorId,
                $date,
                $writeOffNo,
                $description,
                [
                    ['account' => (int) $expenseAccount->id, 'debit' => $totalCost, 'credit' => 0, 'description' => $description],
                    ['account' => (int) $inventoryAccount->id, 'debit' => 0, 'credit' => $totalCost, 'description' => $description],
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Kitchen loss journal failed: ' . $e->getMessage(), [
                'created_by' => $creatorId,
                'write_off_no' => $writeOffNo,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // POS cancellation link (Phase 2 reuse)
    // -----------------------------------------------------------------

    /**
     * Record the waste of a POS line cancelled after preparation as a
     * kitchen loss, linked to the WarehouseLoss row Phase 2 already
     * created. The stock was already deducted when the line was sent
     * to production, so this loss is created already POSTED with
     * stock_already_deducted — it is a log of food that is already
     * gone, not a request, so the approval step does not apply here
     * (the cancellation itself was already authorized when the line
     * was voided). The category follows the destination; disposal of
     * prepared food is recorded as burnt / spoiled waste.
     *
     * When the kitchen_losses table does not exist (a minimal test
     * schema that builds only the Phase 2 tables), nothing is recorded
     * here — the WarehouseLoss row is the loss record there.
     */
    public function linkPosCancellationLoss(
        WarehouseLoss $writeOff,
        Pos $pos,
        PosProduct $line,
        int $creatorId,
        string $destination,
        ?User $user,
    ): ?KitchenLoss {
        if (! Schema::hasTable('kitchen_losses')) {
            return null;
        }

        $category = match ($destination) {
            KitchenPosSaleService::DESTINATION_STAFF_MEAL => KitchenLoss::CATEGORY_STAFF_MEAL,
            KitchenPosSaleService::DESTINATION_COMPLIMENTARY => KitchenLoss::CATEGORY_COMPLIMENTARY,
            KitchenPosSaleService::DESTINATION_TASTING => KitchenLoss::CATEGORY_TASTING,
            default => KitchenLoss::CATEGORY_BURNT_SPOILED,
        };

        $batchId = null;
        if (Schema::hasTable('kitchen_pos_batch_consumptions')) {
            $batchId = KitchenPosBatchConsumption::where('pos_product_id', (int) $line->id)
                ->where('created_by', $creatorId)
                ->orderBy('id')
                ->value('kitchen_production_batch_id');
            $batchId = $batchId !== null ? (int) $batchId : null;
        }

        $orderId = null;
        if ($batchId !== null) {
            $orderId = KitchenProductionBatch::where('id', $batchId)
                ->where('created_by', $creatorId)
                ->value('kitchen_production_order_id');
            $orderId = $orderId !== null ? (int) $orderId : null;
        }

        return KitchenLoss::create([
            'loss_number' => $this->nextLossNumber($creatorId),
            'category' => $category,
            'product_id' => (int) $line->product_id,
            'quantity' => (float) $line->quantity,
            'unit_id' => null,
            'unit_cost' => (float) ($writeOff->unit_cost ?? 0),
            'total_cost' => (float) ($writeOff->cost_of_goods ?? 0),
            'warehouse_id' => (int) ($writeOff->warehouse_id ?? ($line->warehouse_id ?? $pos->warehouse_id ?? 0)),
            'reason' => __('Kitchen loss after preparation — POS :pos line :line, destination: :destination', [
                'pos' => $pos->pos_id ?? $pos->id,
                'line' => $line->id,
                'destination' => $destination,
            ]),
            'recorded_by' => $user ? (int) $user->id : null,
            'status' => KitchenLoss::STATUS_POSTED,
            'approved_by' => $user ? (int) $user->id : null,
            'approved_at' => now(),
            'posted_by' => $user ? (int) $user->id : null,
            'posted_at' => now(),
            'reuse_flag' => in_array($destination, [
                KitchenPosSaleService::DESTINATION_STAFF_MEAL,
                KitchenPosSaleService::DESTINATION_COMPLIMENTARY,
                KitchenPosSaleService::DESTINATION_TASTING,
            ], true),
            'food_safety_note' => null,
            'reuse_destination' => in_array($destination, [
                KitchenPosSaleService::DESTINATION_STAFF_MEAL,
                KitchenPosSaleService::DESTINATION_COMPLIMENTARY,
                KitchenPosSaleService::DESTINATION_TASTING,
            ], true) ? $destination : null,
            'kitchen_production_order_id' => $orderId,
            'pos_id' => (int) $pos->id,
            'pos_product_id' => (int) $line->id,
            'kitchen_production_batch_id' => $batchId,
            'warehouse_loss_id' => (int) $writeOff->id,
            'stock_already_deducted' => true,
            'created_by' => $creatorId,
        ]);
    }

    // -----------------------------------------------------------------
    // Reconciliation (plan §9)
    // -----------------------------------------------------------------

    /**
     * Batch reconciliation: produced - sold - lost = remaining.
     * Sold is the batch quantity POS lines consumed, net of quantities
     * returned to the batch (cancellation before preparation). Lost is
     * the posted kitchen losses linked to the batch. A batch that does
     * not reconcile is a bug, not a rounding difference.
     *
     * @return array{produced: float, sold: float, lost: float, remaining: float, balanced: bool, difference: float}
     */
    public function reconciliation(KitchenProductionBatch $batch, int $creatorId): array
    {
        $produced = round((float) $batch->quantity_produced, 4);
        $remaining = round((float) $batch->quantity_remaining, 4);

        $sold = 0.0;
        if (Schema::hasTable('kitchen_pos_batch_consumptions')) {
            $sold = round((float) KitchenPosBatchConsumption::where('kitchen_production_batch_id', (int) $batch->id)
                ->where('created_by', $creatorId)
                ->sum(DB::raw('quantity - quantity_restored')), 4);
        }

        $lost = 0.0;
        if (Schema::hasTable('kitchen_losses')) {
            $lost = round((float) KitchenLoss::where('kitchen_production_batch_id', (int) $batch->id)
                ->where('created_by', $creatorId)
                ->where('status', KitchenLoss::STATUS_POSTED)
                ->where('stock_already_deducted', false)
                ->sum('quantity'), 4);
        }

        $difference = round($produced - $sold - $lost - $remaining, 4);

        return [
            'produced' => $produced,
            'sold' => $sold,
            'lost' => $lost,
            'remaining' => $remaining,
            'balanced' => abs($difference) < 0.0001,
            'difference' => $difference,
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    public function nextLossNumber(int $creatorId): string
    {
        $year = now()->format('Y');
        $prefix = 'KL-'.$year.'-';

        $count = KitchenLoss::where('created_by', $creatorId)
            ->where('loss_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
