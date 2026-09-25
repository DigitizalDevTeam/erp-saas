<?php

namespace App\Services;

use App\Models\Pos;
use App\Models\PosAgentPrinter;
use App\Models\PosBranchPrintConfig;
use App\Models\PosPrintJob;
use App\Models\PosPrinterAgentHeartbeat;
use App\Models\PosProduct;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\ProductVariant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PosPrinterAgentService
{
    /**
     * Build per-line product details for kitchen/bar agent slips.
     *
     * @param  array<int, array{product_id?: int, quantity?: float, price?: float}>|null  $selectedOptions
     * @return array<string, mixed>
     */
    public static function buildLineProductDetails(
        ProductService $product,
        ?int $variantId,
        ?array $selectedOptions,
        string $note,
        float $unitPrice,
        int $qty
    ): array {
        $size = '';
        $color = '';
        $sizeLabel = trim((string) ($product->variant_size_label ?? '')) ?: 'Size';
        $colorLabel = trim((string) ($product->variant_color_label ?? '')) ?: 'Color';
        $sku = trim((string) ($product->sku ?? ''));
        $salePrice = (float) ($product->sale_price ?? 0);
        $variantsText = 'N/A';

        if ($variantId) {
            $variant = ProductVariant::where('id', $variantId)
                ->where('product_service_id', $product->id)
                ->first();
            if ($variant) {
                $size = trim((string) ($variant->size ?? ''));
                $color = trim((string) ($variant->color ?? ''));
                if (trim((string) ($variant->sku ?? '')) !== '') {
                    $sku = trim((string) $variant->sku);
                }
                if ($variant->sale_price !== null) {
                    $salePrice = (float) $variant->sale_price;
                }
                $parts = [];
                if ($size !== '') {
                    $parts[] = $sizeLabel . ': ' . $size;
                }
                if ($color !== '') {
                    $parts[] = $colorLabel . ': ' . $color;
                }
                if ($parts !== []) {
                    $variantsText = implode(' · ', $parts);
                } elseif ($sku !== '') {
                    $variantsText = $sku;
                }
            }
        }

        $optionsList = [];
        $groups = [];
        $selected = $selectedOptions ?? [];
        if ($selected !== []) {
            $optionIds = collect($selected)->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->all();
            $optionProducts = $optionIds === []
                ? collect()
                : ProductService::whereIn('id', $optionIds)->get(['id', 'name', 'sku'])->keyBy('id');
            $optionItems = ProductOptionItem::query()
                ->where('parent_product_id', $product->id)
                ->whereIn('option_product_id', $optionIds ?: [0])
                ->get()
                ->keyBy('option_product_id');

            foreach ($selected as $sel) {
                $oid = (int) ($sel['product_id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $op = $optionProducts->get($oid);
                $item = $optionItems->get($oid);
                $group = $item ? trim((string) ($item->option_group ?? '')) : '';
                if ($group !== '') {
                    $groups[$group] = true;
                }
                $optionsList[] = [
                    'name' => $op ? (string) ($op->name ?? '') : ('#' . $oid),
                    'group' => $group !== '' ? $group : null,
                    'quantity' => (float) ($sel['quantity'] ?? 1),
                    'price' => isset($sel['price']) ? (float) $sel['price'] : null,
                ];
            }
        }

        $ingredientsList = [];
        $recipes = ProductRecipe::where('product_id', $product->id)
            ->with('ingredientProduct')
            ->orderBy('id')
            ->get();
        foreach ($recipes as $recipe) {
            $ing = $recipe->ingredientProduct;
            if (! $ing) {
                continue;
            }
            $ingredientsList[] = [
                'name' => (string) ($ing->name ?? ''),
                'qty' => (float) ($recipe->quantity ?? 0),
            ];
        }

        $lineTotal = $qty * $unitPrice;

        return [
            'name' => (string) ($product->name ?? ''),
            'qty' => $qty,
            'price' => $unitPrice,
            'line_total' => $lineTotal,
            'note' => $note,
            'sku' => $sku !== '' ? $sku : 'N/A',
            'sale_price' => $salePrice,
            'size' => $size !== '' ? $size : 'N/A',
            'color' => $color !== '' ? $color : 'N/A',
            'size_label' => $sizeLabel,
            'color_label' => $colorLabel,
            'variants' => $variantsText,
            'options' => $optionsList,
            'groups' => array_keys($groups),
            'ingredients' => $ingredientsList,
        ];
    }

    /**
     * Upsert Windows printers reported by the agent for a branch.
     *
     * @param  array<int, array{system_name: string, display_name?: string|null}>  $printers
     * @return array<int, PosAgentPrinter>
     */
    public static function syncPrinters(int $branchId, int $creatorId, array $printers): array
    {
        $now = now();
        $synced = [];

        foreach ($printers as $printer) {
            $systemName = trim((string) ($printer['system_name'] ?? ''));
            if ($systemName === '') {
                continue;
            }
            $displayName = trim((string) ($printer['display_name'] ?? $systemName));
            if ($displayName === '') {
                $displayName = $systemName;
            }

            $row = PosAgentPrinter::updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'system_name' => $systemName,
                ],
                [
                    'created_by' => $creatorId,
                    'display_name' => $displayName,
                    'last_seen_at' => $now,
                ]
            );
            $synced[] = $row;
        }

        return $synced;
    }

    public static function getConfig(int $branchId, int $creatorId): ?PosBranchPrintConfig
    {
        return PosBranchPrintConfig::where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->first();
    }

    public static function saveConfig(
        int $branchId,
        int $creatorId,
        ?int $kitchenPrinterId,
        ?int $barPrinterId
    ): PosBranchPrintConfig {
        if ($kitchenPrinterId) {
            $kitchenOk = PosAgentPrinter::where('id', $kitchenPrinterId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->exists();
            if (! $kitchenOk) {
                $kitchenPrinterId = null;
            }
        } else {
            $kitchenPrinterId = null;
        }

        if ($barPrinterId) {
            $barOk = PosAgentPrinter::where('id', $barPrinterId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->exists();
            if (! $barOk) {
                $barPrinterId = null;
            }
        } else {
            $barPrinterId = null;
        }

        return PosBranchPrintConfig::updateOrCreate(
            [
                'branch_id' => $branchId,
                'created_by' => $creatorId,
            ],
            [
                'kitchen_printer_id' => $kitchenPrinterId,
                'bar_printer_id' => $barPrinterId,
            ]
        );
    }

    /**
     * Split print lines by kitchen/bar and enqueue agent jobs when printers are configured.
     *
     * @param  array<int, array{name?: string, qty?: int, note?: string, print_zone?: string, pos_printer_id?: int|null}>  $printLines
     * @param  array<string, mixed>  $header
     * @return array<int, PosPrintJob>
     */
    public static function enqueueProductionJobs(
        int $branchId,
        int $creatorId,
        ?int $posId,
        array $printLines,
        array $header = []
    ): array {
        if ($printLines === []) {
            return [];
        }

        $config = self::getConfig($branchId, $creatorId);
        if (! $config) {
            return [];
        }

        $kitchenPrinter = $config->kitchen_printer_id
            ? PosAgentPrinter::where('id', $config->kitchen_printer_id)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->first()
            : null;
        $barPrinter = $config->bar_printer_id
            ? PosAgentPrinter::where('id', $config->bar_printer_id)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->first()
            : null;

        if (! $kitchenPrinter && ! $barPrinter) {
            return [];
        }

        $byZone = [
            PosPrintJob::ZONE_KITCHEN => [],
            PosPrintJob::ZONE_BAR => [],
        ];

        foreach ($printLines as $line) {
            $zone = PosPrintRoutingService::normalizePrintZone($line['print_zone'] ?? PosProduct::PRINT_ZONE_KITCHEN);
            $qty = (int) ($line['qty'] ?? 0);
            $price = (float) ($line['price'] ?? 0);
            $lineTotal = isset($line['line_total'])
                ? (float) $line['line_total']
                : ($qty * $price);
            $row = [
                'name' => (string) ($line['name'] ?? ''),
                'qty' => $qty,
                'price' => $price,
                'line_total' => $lineTotal,
                'note' => (string) ($line['note'] ?? ''),
                'sku' => (string) ($line['sku'] ?? 'N/A'),
                'sale_price' => (float) ($line['sale_price'] ?? $price),
                'size' => (string) ($line['size'] ?? 'N/A'),
                'color' => (string) ($line['color'] ?? 'N/A'),
                'size_label' => (string) ($line['size_label'] ?? 'Size'),
                'color_label' => (string) ($line['color_label'] ?? 'Color'),
                'variants' => (string) ($line['variants'] ?? 'N/A'),
                'options' => is_array($line['options'] ?? null) ? $line['options'] : [],
                'groups' => is_array($line['groups'] ?? null) ? $line['groups'] : [],
                'ingredients' => is_array($line['ingredients'] ?? null) ? $line['ingredients'] : [],
            ];

            if ($zone === PosProduct::PRINT_ZONE_BOTH) {
                $byZone[PosPrintJob::ZONE_KITCHEN][] = $row;
                $byZone[PosPrintJob::ZONE_BAR][] = $row;
            } elseif ($zone === PosProduct::PRINT_ZONE_BAR) {
                $byZone[PosPrintJob::ZONE_BAR][] = $row;
            } else {
                $byZone[PosPrintJob::ZONE_KITCHEN][] = $row;
            }
        }

        $created = [];
        foreach ($byZone as $zone => $rows) {
            if ($rows === []) {
                continue;
            }
            $printer = $zone === PosPrintJob::ZONE_BAR ? $barPrinter : $kitchenPrinter;
            if (! $printer) {
                continue;
            }

            $title = $zone === PosPrintJob::ZONE_BAR
                ? __('pos.bar_slip')
                : __('pos.kitchen_slip');

            $subtotal = 0.0;
            foreach ($rows as $r) {
                $subtotal += (float) ($r['line_total'] ?? 0);
            }

            $created[] = PosPrintJob::create([
                'branch_id' => $branchId,
                'created_by' => $creatorId,
                'pos_id' => $posId,
                'zone' => $zone,
                'system_printer_name' => $printer->system_name,
                'title' => $title,
                'payload' => [
                    'header' => $header,
                    'rows' => $rows,
                    'totals' => [
                        'subtotal' => round($subtotal, 2),
                        'total' => round($subtotal, 2),
                        'item_count' => count($rows),
                    ],
                    'zone' => $zone,
                    'title' => $title,
                ],
                'status' => PosPrintJob::STATUS_PENDING,
            ]);
        }

        return $created;
    }

    /**
     * Claim pending jobs for the agent (pending → printing).
     *
     * @return array<int, PosPrintJob>
     */
    public static function claimPendingJobs(int $branchId, int $creatorId, int $limit = 20): array
    {
        return DB::transaction(function () use ($branchId, $creatorId, $limit) {
            $jobs = PosPrintJob::where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->where('status', PosPrintJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($jobs as $job) {
                $job->status = PosPrintJob::STATUS_PRINTING;
                $job->claimed_at = $now;
                $job->save();
            }

            return $jobs->all();
        });
    }

    public static function acknowledgeJob(PosPrintJob $job, string $status, ?string $errorMessage = null): PosPrintJob
    {
        if (! in_array($status, [PosPrintJob::STATUS_DONE, PosPrintJob::STATUS_FAILED], true)) {
            $status = PosPrintJob::STATUS_FAILED;
        }

        $job->status = $status;
        $job->error_message = $status === PosPrintJob::STATUS_FAILED
            ? (string) ($errorMessage ?: 'Print failed')
            : null;
        $job->printed_at = $status === PosPrintJob::STATUS_DONE ? now() : null;
        $job->save();

        return $job;
    }

    /**
     * Build a small header for production slips from a Pos order.
     *
     * @return array<string, mixed>
     */
    public static function buildOrderHeader(?Pos $pos): array
    {
        if (! $pos) {
            return [];
        }

        $tableName = '';
        $waiterName = '';

        if ($pos->table_id) {
            $table = $pos->relationLoaded('table') && $pos->table
                ? $pos->table
                : \App\Models\PosTable::with('waiterUser')->find($pos->table_id);
            if ($table) {
                $tableName = (string) ($table->name ?? '');
                if ($table->waiterUser) {
                    $waiterName = (string) ($table->waiterUser->name ?? '');
                } elseif (! empty($table->waiter_user_id)) {
                    $waiter = User::find($table->waiter_user_id);
                    $waiterName = $waiter ? (string) ($waiter->name ?? '') : '';
                }
            }
        }

        if ($waiterName === '' && ! empty($pos->pos_user_id)) {
            $waiter = $pos->relationLoaded('posUser') && $pos->posUser
                ? $pos->posUser
                : User::find($pos->pos_user_id);
            if ($waiter) {
                $waiterName = (string) ($waiter->name ?? '');
            }
        }

        if ($waiterName === '') {
            try {
                $actorId = \App\Services\PosActorService::posActorId();
                if ($actorId) {
                    $actor = User::find($actorId);
                    $waiterName = $actor ? (string) ($actor->name ?? '') : '';
                }
            } catch (\Throwable $e) {
                // ignore when no POS actor context
            }
        }

        $now = now();

        return [
            'pos_id' => (int) $pos->id,
            'pos_number' => (string) ($pos->pos_id ?? $pos->id),
            'order_no' => (string) ($pos->pos_id ?? $pos->id),
            'order_type' => (string) ($pos->order_type ?? ''),
            'table_name' => $tableName,
            'table_no' => $tableName,
            'guest_name' => (string) ($pos->guest_name ?? ''),
            'waiter_name' => $waiterName,
            'date' => $now->format('Y-m-d'),
            'time' => $now->format('H:i:s'),
            'sent_at' => $now->toDateTimeString(),
        ];
    }

    /**
     * Resolve company owner from a branch (no authenticated user required).
     */
    public static function resolveBranch(int $branchId): ?Branch
    {
        return Branch::where('id', $branchId)->where('is_active', true)->first();
    }

    /**
     * @return array<int, array{id: int, name: string, pos_type: string|null}>
     */
    public static function listAgentBranches(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_type', 'created_by'])
            ->map(static function (Branch $b) {
                return [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                    'pos_type' => $b->pos_type,
                ];
            })
            ->values()
            ->all();
    }

    public static function recordHeartbeat(int $branchId, int $creatorId, ?string $machineName = null): PosPrinterAgentHeartbeat
    {
        return PosPrinterAgentHeartbeat::updateOrCreate(
            ['branch_id' => $branchId],
            [
                'created_by' => $creatorId,
                'last_heartbeat_at' => now(),
                'machine_name' => $machineName ? substr($machineName, 0, 255) : null,
            ]
        );
    }

    /**
     * @return array{online: bool, status: string, last_heartbeat_at: string|null, machine_name: string|null}
     */
    public static function agentStatusForBranch(int $branchId, int $creatorId): array
    {
        $row = PosPrinterAgentHeartbeat::where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->first();

        if (! $row) {
            // Also accept heartbeat without created_by mismatch (agent resolved from branch)
            $row = PosPrinterAgentHeartbeat::where('branch_id', $branchId)->first();
        }

        $online = $row ? $row->isOnline() : false;

        return [
            'online' => $online,
            'status' => $online ? 'online' : 'offline',
            'last_heartbeat_at' => $row && $row->last_heartbeat_at
                ? $row->last_heartbeat_at->toDateTimeString()
                : null,
            'machine_name' => $row ? $row->machine_name : null,
        ];
    }
}
