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
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class PosPrinterAgentService
{
    protected static ?bool $hasInvoiceAgentColumn = null;

    /**
     * Whether pos_branch_print_configs.use_print_agent_for_invoice exists (cached).
     */
    public static function hasInvoiceAgentColumn(): bool
    {
        if (self::$hasInvoiceAgentColumn === null) {
            try {
                self::$hasInvoiceAgentColumn = Schema::hasTable('pos_branch_print_configs')
                    && Schema::hasColumn('pos_branch_print_configs', 'use_print_agent_for_invoice');
            } catch (\Throwable $e) {
                self::$hasInvoiceAgentColumn = false;
            }
        }

        return self::$hasInvoiceAgentColumn;
    }

    /**
     * Invoice/receipt should go to Print Agent when Default Printer is set and the toggle is ON.
     * If the toggle column is missing (migration not run), keep prior behaviour: use agent when a default printer exists.
     */
    public static function usesPrintAgentForInvoice(?PosBranchPrintConfig $config): bool
    {
        if (! $config || ! $config->default_printer_id) {
            return false;
        }
        if (! self::hasInvoiceAgentColumn()) {
            return true;
        }

        return (bool) $config->use_print_agent_for_invoice;
    }

    protected static ?bool $hasSaveImageColumn = null;

    /**
     * Whether pos_branch_print_configs.save_image_when_offline exists (cached).
     */
    public static function hasSaveImageColumn(): bool
    {
        if (self::$hasSaveImageColumn === null) {
            try {
                self::$hasSaveImageColumn = Schema::hasTable('pos_branch_print_configs')
                    && Schema::hasColumn('pos_branch_print_configs', 'save_image_when_offline');
            } catch (\Throwable $e) {
                self::$hasSaveImageColumn = false;
            }
        }

        return self::$hasSaveImageColumn;
    }

    /**
     * Branch option: when the Print Agent / printer is not connected,
     * save the bill/receipt as a PNG image instead of sending a print job.
     */
    public static function saveImageWhenOfflineEnabled(?PosBranchPrintConfig $config): bool
    {
        if (! $config || ! self::hasSaveImageColumn()) {
            return false;
        }

        return (bool) $config->save_image_when_offline;
    }

    /**
     * True when no agent heartbeat is fresh for the branch (printer not connected).
     */
    public static function printerAgentOffline(int $branchId, int $creatorId): bool
    {
        return ! self::agentStatusForBranch($branchId, $creatorId)['online'];
    }

    /**
     * Render the receipt template and store it as a PNG image.
     * Used when printing is requested but the printer is not connected.
     *
     * @return array{path: string, url: string, filename: string}|null
     */
    public static function saveReceiptImageForPos(Pos $pos, ?User $user = null): ?array
    {
        $user = $user ?: ($pos->posUser ?: User::find($pos->created_by));
        if (! $user) {
            return null;
        }

        try {
            $built = self::buildTemplateReceiptJobPayload($pos, $user);
            $png = '';
            if (! empty($built['has_template_image']) && ! empty($built['payload']['image_base64'])) {
                $png = (string) base64_decode((string) $built['payload']['image_base64'], true);
            }
            if ($png === '') {
                $html = self::renderReceiptTemplateHtml($pos, $user);
                if ($html === '') {
                    return null;
                }
                $layout = Utility::normalizePosPrintLayout(
                    (Utility::settingsById($pos->created_by)['pos_print_layout'] ?? 'slip')
                );
                $png = (string) self::screenshotReceiptHtml($html, $layout);
            }
            if ($png === '') {
                return null;
            }

            $filename = 'receipt-pos-' . (int) $pos->id . '-' . now()->format('Ymd-His') . '.png';
            $dir = 'uploads/pos-receipt-images';
            $path = $dir . '/' . $filename;

            $storage = Utility::getStorageSetting();
            $driver = $storage['storage_setting'] ?? 'local';
            if ($driver === 'local') {
                $absDir = storage_path($dir);
                if (! is_dir($absDir)) {
                    mkdir($absDir, 0775, true);
                }
                file_put_contents($absDir . '/' . $filename, $png);
            } else {
                \Illuminate\Support\Facades\Storage::disk($driver)->put($path, $png);
            }

            return [
                'path' => $path,
                'url' => Utility::get_file($path),
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            \Log::warning('POS receipt image save failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Enqueue a native receipt/invoice job when Print Agent invoice mode is enabled for the branch.
     * Used after Pay (auto) and from the receipt Print button. Does not touch kitchen/bar jobs.
     */
    public static function enqueueReceiptForPosIfEnabled(Pos $pos, ?User $user = null): ?PosPrintJob
    {
        $branchId = (int) ($pos->branch_id ?? 0);
        $creatorId = (int) ($pos->created_by ?? 0);
        if ($branchId <= 0 || $creatorId <= 0) {
            return null;
        }

        $config = self::getConfig($branchId, $creatorId);
        if (! self::usesPrintAgentForInvoice($config)) {
            return null;
        }

        $pos->loadMissing(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch']);
        $user = $user ?: ($pos->posUser ?: User::find($creatorId));

        // Printer not connected + branch option on: keep the receipt as an image.
        $cfg = self::getConfig($branchId, $creatorId);
        if (self::saveImageWhenOfflineEnabled($cfg) && self::printerAgentOffline($branchId, $creatorId)) {
            $saved = self::saveReceiptImageForPos($pos, $user);
            if ($saved) {
                \Log::info('POS receipt saved as image (printer offline): ' . $saved['path']);

                return null;
            }
        }

        $isPaid = $pos->posPayments->isNotEmpty();
        $title = $isPaid ? __('Receipt') : __('Account Statement');

        $payload = self::buildReceiptPayloadFromPos($pos, $user);
        $payload['render'] = 'native';
        unset($payload['image_base64'], $payload['image_mime']);

        return self::enqueueReceiptJob($branchId, $creatorId, (int) $pos->id, $payload, $title);
    }

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
            ->with(['ingredientProduct', 'unit'])
            ->orderBy('id')
            ->get();
        $lineQty = max(1, (float) $qty);
        foreach ($recipes as $recipe) {
            $ing = $recipe->ingredientProduct;
            if (! $ing) {
                continue;
            }
            $ingredientsList[] = [
                'name' => (string) ($ing->name ?? ''),
                'qty' => round((float) ($recipe->quantity ?? 0) * $lineQty, 4),
                'unit' => $recipe->unit ? (string) ($recipe->unit->name ?? '') : '',
            ];
        }

        $lineTotal = ProductOptionService::calculateLineExtendedTotal(
            $unitPrice,
            (float) $qty,
            $selected
        );

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
        ?int $barPrinterId,
        ?int $defaultPrinterId = null,
        ?bool $usePrintAgentForInvoice = null,
        ?bool $saveImageWhenOffline = null
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

        if ($defaultPrinterId) {
            $defaultOk = PosAgentPrinter::where('id', $defaultPrinterId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->exists();
            if (! $defaultOk) {
                $defaultPrinterId = null;
            }
        } else {
            $defaultPrinterId = null;
        }

        $attrs = [
            'kitchen_printer_id' => $kitchenPrinterId,
            'bar_printer_id' => $barPrinterId,
            'default_printer_id' => $defaultPrinterId,
        ];
        if ($usePrintAgentForInvoice !== null && self::hasInvoiceAgentColumn()) {
            $attrs['use_print_agent_for_invoice'] = (bool) $usePrintAgentForInvoice;
        }
        if ($saveImageWhenOffline !== null && self::hasSaveImageColumn()) {
            $attrs['save_image_when_offline'] = (bool) $saveImageWhenOffline;
        }

        return PosBranchPrintConfig::updateOrCreate(
            [
                'branch_id' => $branchId,
                'created_by' => $creatorId,
            ],
            $attrs
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
            $optionLines = [];
            foreach (is_array($line['options'] ?? null) ? $line['options'] : [] as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $optName = trim((string) ($opt['name'] ?? ''));
                if ($optName === '') {
                    continue;
                }
                $optQty = (float) ($opt['quantity'] ?? 1);
                if ($optQty > 0 && abs($optQty - 1) > 0.0001) {
                    $optName .= ' ×' . rtrim(rtrim(number_format($optQty, 4, '.', ''), '0'), '.');
                }
                $optionLines[] = '+ ' . $optName;
            }
            $rowName = (string) ($line['name'] ?? '');
            if ($optionLines !== []) {
                $rowName .= "\n" . implode("\n", $optionLines);
            }
            $row = [
                'name' => $rowName,
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
     * Enqueue a customer receipt/bill job to the branch default (other prints) printer.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function enqueueReceiptJob(
        int $branchId,
        int $creatorId,
        ?int $posId,
        array $payload,
        ?string $title = null
    ): ?PosPrintJob {
        $config = self::getConfig($branchId, $creatorId);
        if (! $config || ! $config->default_printer_id) {
            return null;
        }

        $printer = PosAgentPrinter::where('id', $config->default_printer_id)
            ->where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->first();
        if (! $printer) {
            return null;
        }

        $title = $title ?: __('Receipt');
        $payload['zone'] = PosPrintJob::ZONE_RECEIPT;
        $payload['title'] = $title;
        $payload['doc_type'] = $payload['doc_type'] ?? 'receipt';

        return PosPrintJob::create([
            'branch_id' => $branchId,
            'created_by' => $creatorId,
            'pos_id' => $posId,
            'zone' => PosPrintJob::ZONE_RECEIPT,
            'system_printer_name' => $printer->system_name,
            'title' => $title,
            'payload' => $payload,
            'status' => PosPrintJob::STATUS_PENDING,
        ]);
    }

    /**
     * Build agent receipt/invoice payload from a Pos order (active lines only).
     * Structured for the Print Agent native Cash Sale template.
     *
     * @return array<string, mixed>
     */
    public static function buildReceiptPayloadFromPos(Pos $pos, ?User $user = null): array
    {
        $pos->loadMissing(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch']);
        $settings = \App\Models\Utility::settingsById($pos->created_by);
        $user = $user ?: ($pos->posUser ?: User::find($pos->created_by));
        $previousLocale = app()->getLocale();
        if ($user && is_string($user->lang) && $user->lang !== '') {
            app()->setLocale($user->lang);
        }

        try {
        $rows = [];
        $subtotalGross = 0.0;
        $vatTotal = 0.0;
        $vatBreakdown = [];

        foreach ($pos->items as $item) {
            if (! empty($item->voided_at) || ! empty($item->removed_from_cart_at)) {
                continue;
            }
            $product = $item->product;
            $price = (float) $item->price;
            $qty = (int) $item->quantity;
            $taxRate = (float) \App\Models\Utility::totalTaxRate($item->tax);
            $amounts = \App\Http\Controllers\PosController::posLineAmountsForItem($item, $product);
            $net = $amounts['net'];
            $vat = $amounts['tax'];
            $lineTotal = $amounts['line_total'];
            $subtotalGross += $lineTotal;
            $vatTotal += $vat;
            $rateKey = (string) $taxRate;
            if (! isset($vatBreakdown[$rateKey])) {
                $vatBreakdown[$rateKey] = [
                    'rate' => $taxRate,
                    'net' => 0.0,
                    'tax' => 0.0,
                ];
            }
            $vatBreakdown[$rateKey]['net'] += $net;
            $vatBreakdown[$rateKey]['tax'] += $vat;
            $rows[] = [
                'name' => $product ? (string) $product->name : __('Product'),
                'qty' => $qty,
                'price' => round($price, 2),
                'line_total' => round($lineTotal, 2),
                'tax_label' => $taxRate > 0 ? (__('VAT') . ' ' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%') : '',
                'note' => (string) ($item->description ?? ''),
            ];
        }

        $discount = (float) $pos->posPayments->sum('discount');
        $paid = (float) $pos->posPayments->sum('amount');
        $tip = (float) $pos->posPayments->sum('tip');
        $changeFromPayments = (float) $pos->posPayments->sum('change_amount');
        $total = max(0, $subtotalGross - $discount);
        $change = $changeFromPayments > 0 ? $changeFromPayments : max(0, $paid - $total);

        $paymentMethods = [];
        $paymentRefs = [];
        $paymentNotes = '';
        foreach ($pos->posPayments as $firstPay) {
            $method = (string) ($firstPay->payment_method ?? '');
            if ($method !== '') {
                $paymentMethods[] = \App\Models\PosPayment::labelForMethod($method);
            }
            $ref = (string) ($firstPay->transaction_id ?? '');
            if ($ref !== '') {
                $paymentRefs[] = $ref;
            }
            if ($paymentNotes === '') {
                $paymentNotes = trim((string) ($firstPay->notes ?? ''));
            }
        }
        if ($paymentMethods === [] && ! empty($pos->payment_method)) {
            $paymentMethods[] = \App\Models\PosPayment::labelForMethod((string) $pos->payment_method);
        }

        $posNumber = '';
        if ($user) {
            $posNumber = $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null);
        } else {
            $posNumber = (string) ($pos->pos_id ?? '');
        }

        $tableName = $pos->table ? (string) $pos->table->name : '';
        $customer = $pos->customer;
        $customerName = ! empty($pos->guest_name)
            ? (string) $pos->guest_name
            : ($customer ? (string) $customer->name : __('pos.walkin_customer'));
        $customerNuit = $customer ? (string) ($customer->tax_number ?? '') : '';
        $customerAddress = $customer
            ? (string) ($customer->billing_address ?? $customer->address ?? '')
            : '';

        $operator = $pos->posUser
            ? (string) $pos->posUser->name
            : ($user ? (string) $user->name : '');

        $companyAddressParts = array_filter([
            $settings['company_address'] ?? null,
            $settings['company_city'] ?? null,
            $settings['company_state'] ?? null,
            $settings['company_zipcode'] ?? null,
            $settings['company_country'] ?? null,
        ]);
        $companyAddress = implode(', ', $companyAddressParts);
        $currency = (string) ($settings['site_currency_symbol'] ?? $settings['site_currency'] ?? 'MT');
        $docType = Pos::normalizeDocumentType($pos->document_type ?? null);
        $documentTitle = Pos::documentTypeLabel($docType);
        $isPaid = $pos->posPayments->isNotEmpty();
        if (! $isPaid) {
            $documentTitle = __('Account Statement');
            $posNumber = Pos::billDocumentNumber($posNumber, $settings);
        }

        $issuedAt = $pos->created_at
            ? $pos->created_at->format('Y-m-d H:i:s')
            : (($pos->pos_date ? date('Y-m-d', strtotime($pos->pos_date)) : date('Y-m-d')) . ' ' . date('H:i:s'));

        $receiptLinkUrl = '';
        try {
            $receiptLinkUrl = \Illuminate\Support\Facades\URL::signedRoute(
                'pos.receipt.link',
                ['id' => \Illuminate\Support\Facades\Crypt::encrypt($pos->id)]
            );
        } catch (\Throwable $e) {
            $receiptLinkUrl = '';
        }

        $showQr = ($settings['pos_qr_display'] ?? 'on') !== 'off';
        $qrBase64 = '';
        $qrPayload = $receiptLinkUrl !== ''
            ? $receiptLinkUrl
            : ((string) ($settings['company_name'] ?? '') . ' - ' . $posNumber . ' - ' . $issuedAt);
        if ($showQr) {
            try {
                $png = \DNS2D::getBarcodePNG($qrPayload, 'QRCODE', 4, 4);
                if (is_string($png) && $png !== '') {
                    $qrBase64 = $png;
                }
            } catch (\Throwable $e) {
                $qrBase64 = '';
            }
        }

        $logoBase64 = self::resolveReceiptLogoBase64($settings);

        $companyWebsite = (string) ($settings['company_website'] ?? 'www.uthara.co.mz');
        $companyEmail = (string) ($settings['company_email'] ?? $settings['mail_from_address'] ?? '');

        $payload = [
            'doc_type' => $isPaid ? 'receipt' : 'bill',
            'is_credit' => $docType === Pos::DOCUMENT_TYPE_CREDIT_SALE,
            'template' => Utility::normalizeAgentInvoiceTemplate($settings['pos_agent_invoice_template'] ?? 'elegant'),
            'render' => 'native',
            'header' => [
                'company' => (string) ($settings['company_name'] ?? ''),
                'company_address' => $companyAddress,
                'company_phone' => (string) ($settings['company_telephone'] ?? ''),
                'company_email' => $companyEmail,
                'company_website' => $companyWebsite,
                'nuit' => (string) ($settings['vat_number'] ?? $settings['registration_number'] ?? ''),
                'document_title' => $documentTitle,
                'pos_number' => $posNumber,
                'copy_label' => __('Original'),
                'issued_at' => $issuedAt,
                'table' => $tableName,
                'customer' => $customerName,
                'customer_nuit' => $customerNuit,
                'customer_address' => $customerAddress,
                'currency' => $currency,
                'date' => $pos->pos_date ? date('Y-m-d', strtotime($pos->pos_date)) : date('Y-m-d'),
                'time' => date('H:i:s'),
                'operator' => $operator,
                'warehouse' => $pos->warehouse ? (string) $pos->warehouse->name : '',
                'branch' => $pos->branch ? (string) $pos->branch->name : '',
                'logo_base64' => $logoBase64,
            ],
            'rows' => $rows,
            'totals' => [
                'subtotal' => round($subtotalGross, 2),
                'discount' => round($discount, 2),
                'vat' => round($vatTotal, 2),
                'tip' => round($tip, 2),
                'total' => round($total, 2),
                'paid' => round($paid, 2),
                'change' => round($change, 2),
                'item_count' => count($rows),
            ],
            'vat_breakdown' => array_values(array_map(static function (array $row) {
                return [
                    'rate' => (float) $row['rate'],
                    'net' => round((float) $row['net'], 2),
                    'tax' => round((float) $row['tax'], 2),
                ];
            }, $vatBreakdown)),
            'payment' => [
                'method' => $paymentMethods ? implode(', ', array_unique($paymentMethods)) : '',
                'reference' => $paymentRefs ? implode(', ', $paymentRefs) : '',
                'notes' => $paymentNotes,
            ],
            'qr_base64' => $qrBase64,
            'qr_payload' => $qrPayload,
            'labels' => [
                'nuit' => __('NUIT'),
                'phone' => __('Phone'),
                'no' => __('No.'),
                'issued_at' => __('Issued at'),
                'customer' => __('Customer'),
                'address' => __('Address'),
                'currency' => __('Currency'),
                'table' => __('pos.table'),
                'description' => __('Description'),
                'qty' => __('Qty'),
                'price' => __('Price'),
                'total' => __('Total'),
                'sub_total' => __('Sub Total'),
                'discount' => __('Discount'),
                'vat' => __('VAT'),
                'tip' => __('Tip'),
                'paid' => __('Paid'),
                'change' => __('Troco'),
                'net_amount' => __('Net Amount'),
                'tax' => __('Tax'),
                'payment' => __('Payment Method'),
                'notes' => __('Notes'),
                'reference' => __('Reference'),
                'operator' => __('Operator'),
                'branch' => __('Branch'),
                'note' => __('Note'),
                'due_amount' => __('Due Amount'),
                'client_signature' => __('pos.client_signature_for_credit'),
                'date' => __('Date'),
                'invoice_no' => __('Invoice No'),
                'grand_total' => __('Grand Total'),
                'product' => __('Product'),
            ],
            'footer' => [
                'thank_you' => __('pos.thank_you'),
                'generated_by' => __('Invoice generated by ERPSaaS'),
                'website' => $companyWebsite . ($companyEmail !== '' ? ' · ' . $companyEmail : ''),
                'processed' => __('pos.document_processed_by_computer'),
            ],
        ];

        return $payload;
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Template name and logo fingerprint the agent applies on heartbeat.
     *
     * @return array{invoice_template: string, logo_hash: string}
     */
    public static function printProfileForCreator(int $creatorId): array
    {
        $settings = Utility::settingsById($creatorId);
        $bytes = self::receiptLogoBytes($creatorId, $settings);

        return [
            'invoice_template' => Utility::normalizeAgentInvoiceTemplate($settings['pos_agent_invoice_template'] ?? 'elegant'),
            'logo_hash' => ($bytes !== null && $bytes !== '') ? md5($bytes) : '',
        ];
    }

    /**
     * Raw POS logo bytes (pos_logo, else company logo).
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function receiptLogoBytes(int $creatorId, ?array $settings = null): ?string
    {
        $settings = $settings ?? Utility::settingsById($creatorId);
        $encoded = self::resolveReceiptLogoBase64($settings);
        if ($encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Same logo as Blade slip receipts: pos_logo, else company_logo_dark.
     * Returns raw base64 for the Print Agent (no data: prefix).
     *
     * @param  array<string, mixed>  $settings  Creator settings (settingsById)
     */
    protected static function resolveReceiptLogoBase64(array $settings): string
    {
        try {
            $posLogo = trim((string) ($settings['pos_logo'] ?? ''));
            if ($posLogo === '') {
                $posLogo = trim((string) (\App\Models\Utility::getValByName('pos_logo') ?? ''));
            }
            $companyLogo = trim((string) ($settings['company_logo_dark'] ?? ''));
            if ($companyLogo === '') {
                $companyLogo = trim((string) (\App\Models\Utility::getValByName('company_logo_dark') ?? ''));
            }
            if ($companyLogo === '') {
                $companyLogo = 'logo-dark.png';
            }

            $relativePaths = [];
            if ($posLogo !== '') {
                $relativePaths[] = 'pos_logo/' . ltrim($posLogo, '/');
            }
            $relativePaths[] = 'uploads/logo/' . ltrim($companyLogo, '/');

            foreach ($relativePaths as $rel) {
                $bytes = self::readLogoFileBytes($rel);
                if ($bytes !== null && $bytes !== '') {
                    return base64_encode($bytes);
                }
            }
        } catch (\Throwable $e) {
            // ignore logo failures
        }

        return '';
    }

    /**
     * Read logo bytes from local disk or configured storage disk (local/s3/wasabi).
     */
    protected static function readLogoFileBytes(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $localCandidates = [
            storage_path('app/public/' . $relativePath),
            public_path($relativePath),
            storage_path('app/' . $relativePath),
        ];
        foreach ($localCandidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                $bytes = @file_get_contents($path);
                if ($bytes !== false && $bytes !== '') {
                    return $bytes;
                }
            }
        }

        try {
            $storageSettings = Utility::getStorageSetting();
            $diskName = (string) ($storageSettings['storage_setting'] ?? 'local');
            if ($diskName === 'wasabi') {
                config([
                    'filesystems.disks.wasabi.key' => $storageSettings['wasabi_key'] ?? null,
                    'filesystems.disks.wasabi.secret' => $storageSettings['wasabi_secret'] ?? null,
                    'filesystems.disks.wasabi.region' => $storageSettings['wasabi_region'] ?? null,
                    'filesystems.disks.wasabi.bucket' => $storageSettings['wasabi_bucket'] ?? null,
                    'filesystems.disks.wasabi.endpoint' => 'https://s3.' . ($storageSettings['wasabi_region'] ?? '') . '.wasabisys.com',
                ]);
            } elseif ($diskName === 's3') {
                config([
                    'filesystems.disks.s3.key' => $storageSettings['s3_key'] ?? null,
                    'filesystems.disks.s3.secret' => $storageSettings['s3_secret'] ?? null,
                    'filesystems.disks.s3.region' => $storageSettings['s3_region'] ?? null,
                    'filesystems.disks.s3.bucket' => $storageSettings['s3_bucket'] ?? null,
                    'filesystems.disks.s3.use_path_style_endpoint' => false,
                ]);
            }

            $disk = \Illuminate\Support\Facades\Storage::disk($diskName === 'local' ? 'public' : $diskName);
            if ($disk->exists($relativePath)) {
                $bytes = $disk->get($relativePath);
                if (is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            }
            // Local disk often stores under the path without "public/" prefix quirks
            if ($diskName === 'local') {
                $localDisk = \Illuminate\Support\Facades\Storage::disk('local');
                if ($localDisk->exists('public/' . $relativePath)) {
                    $bytes = $localDisk->get('public/' . $relativePath);
                    if (is_string($bytes) && $bytes !== '') {
                        return $bytes;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to URL fetch
        }

        try {
            $dir = dirname($relativePath);
            $file = basename($relativePath);
            $baseUrl = rtrim((string) Utility::get_file($dir), '/');
            if ($baseUrl === '') {
                return null;
            }
            $url = $baseUrl . '/' . ltrim($file, '/');
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'follow_location' => 1],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * Build receipt job payload from the saved POS receipt Blade template (logo, QR, layout).
     * Includes a PNG screenshot of that template for the Print Agent when possible.
     *
     * @return array{payload: array<string, mixed>, has_template_image: bool}
     */
    public static function buildTemplateReceiptJobPayload(Pos $pos, User $user): array
    {
        $payload = self::buildReceiptPayloadFromPos($pos, $user);
        $payload['receipt_layout'] = Utility::normalizeReceiptLayout(
            (Utility::settingsById($pos->created_by)['pos_receipt_layout'] ?? 'existing')
        );
        $payload['print_layout'] = Utility::normalizePosPrintLayout(
            (Utility::settingsById($pos->created_by)['pos_print_layout'] ?? 'slip')
        );

        $html = self::renderReceiptTemplateHtml($pos, $user);
        if ($html === '') {
            return ['payload' => $payload, 'has_template_image' => false];
        }

        $png = self::screenshotReceiptHtml($html, $payload['print_layout']);
        if ($png === null || $png === '') {
            return ['payload' => $payload, 'has_template_image' => false];
        }

        $payload['render'] = 'image';
        $payload['image_mime'] = 'image/png';
        $payload['image_base64'] = base64_encode($png);

        return ['payload' => $payload, 'has_template_image' => true];
    }

    /**
     * Render the same Blade receipt the POS modal uses (saved custom template).
     */
    public static function renderReceiptTemplateHtml(Pos $pos, User $user): string
    {
        try {
            $viewData = self::buildReceiptViewData($pos, $user);
            $viewName = Utility::posReceiptViewName($viewData['settings'] ?? null);
            $inner = view($viewName, $viewData)->render();
            $inner = self::absolutizeHtmlAssetUrls($inner);
            $widthCss = match ($viewData['print_layout'] ?? 'slip') {
                'a4' => '210mm',
                'a5' => '148mm',
                default => '80mm',
            };

            return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                . '<style>'
                . 'html,body{margin:0;padding:0;background:#fff;}'
                . 'body{padding:4px;}'
                . '.no-print-copies,.modal-footer,script{display:none!important;}'
                . '#printarea{max-width:' . $widthCss . ';margin:0 auto;background:#fff;}'
                . '</style></head><body>' . $inner . '</body></html>';
        } catch (\Throwable $e) {
            \Log::warning('POS receipt template render failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildReceiptViewData(Pos $pos, User $user): array
    {
        $pos->loadMissing(['items.product', 'customer', 'table', 'posPayments', 'warehouse', 'posUser', 'branch']);
        $settings = Utility::settingsById($pos->created_by);
        $posPayments = $pos->posPayments;
        $receiptUser = ($pos->pos_user_id ? User::find((int) $pos->pos_user_id) : null) ?: $user;
        $customer = $pos->customer_id ? $pos->customer : null;
        $warehouse = $pos->warehouse;
        $branch = $pos->branch;

        $clientName = ! empty($pos->guest_name)
            ? $pos->guest_name
            : (! empty($customer->name) ? $customer->name : __('pos.walkin_customer'));

        $details = [
            'pos_id' => $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
            'customer' => $customer ? $customer->toArray() : [],
            'warehouse' => $warehouse ? $warehouse->toArray() : [],
            'branch' => $branch ? $branch->toArray() : [],
            'user' => $user->toArray(),
            'date' => $pos->pos_date ?? date('Y-m-d'),
            'client_name' => $clientName,
            'table_name' => $pos->table ? $pos->table->name : null,
            'document_type' => $pos->document_type ?? null,
        ];
        $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
        $details['warehouse']['details'] = '<h7 class="text-dark">' . ucfirst((string) $warehouseName) . '</p></h7>';
        if (! empty($details['customer']['name'])) {
            $details['customer']['details'] = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '</h6>';
        } else {
            $details['customer']['details'] = '<h2 class="h6"><b>' . __('pos.walkin_customer') . '</b></h2>';
        }
        $details['customer']['shippdetails'] = '-';
        $details['user']['details'] = '<h6 class="text-dark"><b>' . ucfirst($user->name) . '</b></h6>';

        $sales = ['data' => [], 'discount' => '0', 'sub_total' => '0', 'total' => '0', 'tip' => '0'];
        $mainsubtotal = 0.0;
        $discount = $posPayments->isEmpty() ? 0.0 : (float) $posPayments->sum('discount');
        $totalTip = (float) $posPayments->sum('tip');
        $vatBreakdown = [];

        foreach ($pos->items as $item) {
            if (! empty($item->voided_at) || ! empty($item->removed_from_cart_at)) {
                continue;
            }
            $product = $item->product ?: ProductService::find($item->product_id);
            $price = (float) $item->price;
            $qty = (int) $item->quantity;
            $taxRate = (float) Utility::totalTaxRate($item->tax);
            $amounts = \App\Http\Controllers\PosController::posLineAmountsForItem($item, $product);
            $subtotal = $amounts['net'];
            $tax = $amounts['tax'];
            $lineTotal = $amounts['line_total'];
            $mainsubtotal += $lineTotal;
            $saleSelected = ProductOptionService::normalizeSelectedOptions($item->selected_options_json ?? null);
            $sales['data'][] = [
                'name' => ($product && $saleSelected !== [])
                    ? ProductOptionService::formatLineName($product, $saleSelected)
                    : ($product ? $product->name : __('Product')),
                'sku' => $product ? ($product->sku ?? '-') : '-',
                'quantity' => $qty,
                'price' => $user->priceFormat($price),
                'tax' => $taxRate . '%',
                'product_tax' => $taxRate . '%',
                'tax_amount' => $user->priceFormat($tax),
                'subtotal' => $user->priceFormat($lineTotal),
                'description' => $item->description ?? '',
            ];

            $key = (string) $taxRate;
            if (! isset($vatBreakdown[$key])) {
                $vatBreakdown[$key] = ['rate' => $taxRate, 'net' => 0, 'vat' => 0, 'amount' => 0];
            }
            $vatBreakdown[$key]['net'] += $subtotal;
            $vatBreakdown[$key]['vat'] += $tax;
            $vatBreakdown[$key]['amount'] += $lineTotal;
        }

        $sales['discount'] = $user->priceFormat($discount);
        $sales['sub_total'] = $user->priceFormat($mainsubtotal);
        $sales['tip'] = $user->priceFormat($totalTip);
        $sales['total'] = $user->priceFormat($mainsubtotal - $discount);

        $printLayout = Utility::normalizePosPrintLayout($settings['pos_print_layout'] ?? 'slip');

        return [
            'details' => $details,
            'sales' => $sales,
            'customer' => $customer,
            'productServices' => collect([]),
            'barcode' => ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()],
            'posPayments' => $posPayments,
            'vatBreakdown' => array_values($vatBreakdown),
            'bankAccounts' => BankAccount::where('created_by', $user->creatorId())
                ->select('id', 'holder_name', 'bank_name', 'account_number')->get(),
            'segundaVia' => false,
            'posIdRaw' => $pos->id,
            'receiptLinkUrl' => URL::signedRoute('pos.receipt.link', ['id' => Crypt::encrypt($pos->id)]),
            'printSource' => 'restaurant',
            'settings' => $settings,
            'receiptUser' => $receiptUser,
            'pos' => $pos,
            'isBillDocument' => $posPayments->isEmpty(),
            'standalone' => false,
            'agentPrint' => true,
            'print_layout' => $printLayout,
        ];
    }

    public static function absolutizeHtmlAssetUrls(string $html): string
    {
        $base = rtrim((string) config('app.url'), '/');

        $html = (string) preg_replace_callback(
            '/\b(src|href)=["\']([^"\']+)["\']/i',
            static function (array $m) use ($base) {
                $attr = $m[1];
                $url = $m[2];
                if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
                    return $m[0];
                }
                if (str_starts_with($url, '/')) {
                    return $attr . '="' . $base . $url . '"';
                }

                return $attr . '="' . $base . '/' . ltrim($url, '/') . '"';
            },
            $html
        );

        // Prefer embedding local images so Chromium does not need network for logos.
        return (string) preg_replace_callback(
            '/\bsrc=["\'](https?:\/\/[^"\']+)["\']/i',
            static function (array $m) {
                $url = $m[1];
                $dataUri = self::tryLocalUrlToDataUri($url);
                if ($dataUri) {
                    return 'src="' . $dataUri . '"';
                }

                return $m[0];
            },
            $html
        );
    }

    public static function tryLocalUrlToDataUri(string $url): ?string
    {
        try {
            $path = parse_url($url, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                return null;
            }
            $relative = ltrim($path, '/');
            $candidates = [
                public_path($relative),
                public_path(preg_replace('#^storage/#', 'storage/app/public/', $relative) ?? $relative),
                storage_path('app/public/' . preg_replace('#^storage/#', '', $relative)),
            ];
            foreach ($candidates as $file) {
                if (! is_string($file) || ! is_file($file)) {
                    continue;
                }
                $mime = mime_content_type($file) ?: 'image/png';
                if (! str_starts_with($mime, 'image/')) {
                    continue;
                }
                $bin = @file_get_contents($file);
                if ($bin === false || $bin === '') {
                    continue;
                }

                return 'data:' . $mime . ';base64,' . base64_encode($bin);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    public static function screenshotReceiptHtml(string $html, string $printLayout = 'slip'): ?string
    {
        if (! class_exists(\Spatie\Browsershot\Browsershot::class)) {
            return null;
        }

        $width = match ($printLayout) {
            'a4' => 794,
            'a5' => 559,
            default => 302,
        };

        try {
            return \Spatie\Browsershot\Browsershot::html($html)
                ->showBackground()
                ->windowSize($width, 1600)
                ->deviceScaleFactor(2)
                ->waitUntilNetworkIdle()
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                ->screenshot();
        } catch (\Throwable $e) {
            \Log::warning('POS receipt Browsershot failed: ' . $e->getMessage());

            return null;
        }
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
                // Always store UTC so status reads are timezone-safe across API vs web (XSS) paths.
                'last_heartbeat_at' => \Carbon\Carbon::now('UTC'),
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
