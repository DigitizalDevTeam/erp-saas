<?php

namespace App\Services;

use App\Models\ProductOptionItem;
use App\Models\ProductService;
use App\Models\Utility;
use Illuminate\Http\Request;

/**
 * Parent product with options: parent unit price stays parent-only; option prices/qtys stay independent.
 * Line money total = parent_price × parent_qty + Σ(option_price × option_qty).
 * Stock moves on selected option products only (not parent), using each option's own quantity.
 */
class ProductOptionService
{
    /**
     * @return array<int, array{product_id: int, quantity: float, price: float}>
     */
    public static function normalizeSelectedOptions(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = json_decode($raw, true);
            if (! is_array($decoded) && str_contains($raw, '&quot;')) {
                $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            }
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            // Accept product_id or id (some clients send id)
            $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $qty = (float) ($row['quantity'] ?? 1);
            if ($qty <= 0) {
                continue;
            }
            $price = isset($row['price']) && $row['price'] !== '' && $row['price'] !== null
                ? round((float) $row['price'], 2)
                : null;
            $entry = [
                'product_id' => $productId,
                'quantity' => $qty,
            ];
            if ($price !== null) {
                $entry['price'] = $price;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $entry['name'] = $name;
            }
            $out[] = $entry;
        }

        return $out;
    }

    public static function encodeOptions(array $selected): ?string
    {
        if ($selected === []) {
            return null;
        }

        return json_encode(array_values($selected), JSON_UNESCAPED_UNICODE);
    }

    /**
     * POS-style stock label for an option product (matches product card qty_badge).
     *
     * @return array{stock_qty: int|string, unit: string}
     */
    public static function optionStockDisplay(ProductService $op, ?int $warehouseQuantity = null, bool $useWarehouse = false): array
    {
        $unit = (! empty($op->unit)) ? (string) $op->unit->name : '';
        // Always show real stock for sellable options. Only kits have no single qty.
        if (($op->type ?? null) === 'kit') {
            $qty = '—';
        } elseif ($useWarehouse) {
            $qty = (int) ($warehouseQuantity ?? 0);
        } else {
            $qty = (int) ($op->quantity ?? 0);
        }

        return [
            'stock_qty' => $qty,
            'unit' => $unit,
        ];
    }

    /**
     * @param  array<int|string, int|float>|null  $warehouseQuantities  product_id => qty
     * @return array{options_enabled: bool, options: array<int, array<string, mixed>>}
     */
    public static function productJsonExtras(ProductService $product, ?int $warehouseId = null, ?array $warehouseQuantities = null): array
    {
        $enabled = (bool) ($product->options_enabled ?? false);
        if (! $enabled) {
            return ['options_enabled' => false, 'options' => []];
        }

        $items = ProductOptionItem::query()
            ->where('parent_product_id', $product->id)
            ->where('created_by', $product->created_by)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $optionIds = $items->pluck('option_product_id')->unique()->filter()->all();
        $products = $optionIds === []
            ? collect()
            : ProductService::whereIn('id', $optionIds)
                ->where('created_by', $product->created_by)
                ->with('unit')
                ->withCount('variants')
                ->get()
                ->keyBy('id');

        $useWarehouse = $warehouseId !== null && $warehouseId > 0;
        if ($useWarehouse && $warehouseQuantities === null && $optionIds !== []) {
            $warehouseQuantities = \App\Models\WarehouseProduct::where('created_by', $product->created_by)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $optionIds)
                ->select('product_id')
                ->selectRaw('SUM(quantity) as quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id')
                ->all();
        }
        $warehouseQuantities = $warehouseQuantities ?? [];

        $options = [];
        foreach ($items as $item) {
            $op = $products->get($item->option_product_id);
            if (! $op || ($op->type ?? '') !== 'product') {
                continue;
            }
            if ((bool) ($op->options_enabled ?? false)) {
                continue;
            }
            $stock = self::optionStockDisplay(
                $op,
                isset($warehouseQuantities[$op->id]) ? (int) $warehouseQuantities[$op->id] : null,
                $useWarehouse
            );
            $options[] = [
                'product_id' => (int) $op->id,
                'name' => $op->name,
                'sku' => $op->sku,
                'sale_price' => $op->exclusiveSaleUnitPrice(),
                'purchase_price' => $op->exclusivePurchaseUnitPrice(),
                'option_group' => $item->option_group ? (string) $item->option_group : null,
                'is_required' => (bool) ($item->is_required ?? false),
                'stock_qty' => $stock['stock_qty'],
                'unit' => $stock['unit'],
                // Option products are products too: show their stock behaviour in the picker.
                'does_not_affect_stock' => ! (bool) ($op->affect_stock ?? true),
                'variants_count' => (int) ($op->variants_count ?? 0),
            ];
        }

        return ['options_enabled' => true, 'options' => $options];
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     * @return array<int, array{product_id: int, quantity: float, price: float}>
     */
    public static function validateSelectedForParent(int $parentId, array $selected, int $creatorId, bool $forPurchase = false): array
    {
        $parent = ProductService::where('id', $parentId)->where('created_by', $creatorId)->first();
        if (! $parent || ! ($parent->options_enabled ?? false)) {
            throw new \RuntimeException(__('This product does not have options.'));
        }
        if ($selected === []) {
            $items = ProductOptionItem::query()
                ->where('parent_product_id', $parentId)
                ->where('created_by', $creatorId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $groups = [];
            foreach ($items as $item) {
                $groupName = trim((string) ($item->option_group ?? ''));
                if ($groupName === '') {
                    continue;
                }
                if (! isset($groups[$groupName])) {
                    $groups[$groupName] = ['required' => false];
                }
                if ($item->is_required) {
                    $groups[$groupName]['required'] = true;
                }
            }

            foreach ($groups as $groupName => $groupMeta) {
                if ($groupMeta['required']) {
                    throw new \RuntimeException(__('Select at least one option for :group.', ['group' => $groupName]));
                }
            }

            throw new \RuntimeException(__('Select at least one product option.'));
        }

        $items = ProductOptionItem::query()
            ->where('parent_product_id', $parentId)
            ->where('created_by', $creatorId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $allowed = $items->pluck('option_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $groups = [];
        foreach ($items as $item) {
            $groupName = trim((string) ($item->option_group ?? ''));
            if ($groupName === '') {
                continue;
            }
            if (! isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'required' => false,
                    'product_ids' => [],
                ];
            }
            $groups[$groupName]['product_ids'][] = (int) $item->option_product_id;
            if ($item->is_required) {
                $groups[$groupName]['required'] = true;
            }
        }

        $selectedIds = array_map(fn ($row) => (int) $row['product_id'], $selected);
        foreach ($groups as $groupName => $groupMeta) {
            $allowedInGroup = $groupMeta['product_ids'];
            $pickedInGroup = array_values(array_intersect($selectedIds, $allowedInGroup));
            $count = count($pickedInGroup);
            if ($groupMeta['required'] && $count < 1) {
                throw new \RuntimeException(__('Select at least one option for :group.', ['group' => $groupName]));
            }
        }

        $validated = [];
        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            if (! in_array($optId, $allowed, true)) {
                throw new \RuntimeException(__('Invalid product option selected.'));
            }
            $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first();
            if (! $opt || ($opt->type ?? '') !== 'product') {
                throw new \RuntimeException(__('Invalid product option selected.'));
            }
            $price = isset($row['price'])
                ? round((float) $row['price'], 2)
                : ($forPurchase
                    ? $opt->exclusivePurchaseUnitPrice()
                    : $opt->exclusiveSaleUnitPrice());
            $validated[] = [
                'product_id' => $optId,
                'quantity' => (float) $row['quantity'],
                'price' => $price,
                'name' => (string) $opt->name,
            ];
        }

        return $validated;
    }

    /**
     * Catalog (shelf) sale/purchase amount for the parent only (options are priced separately).
     *
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateCatalogInclusiveTotal(ProductService $parent, array $selected = [], bool $forPurchase = false): float
    {
        $sum = $forPurchase
            ? (float) ($parent->purchase_price ?? 0)
            : (float) ($parent->sale_price ?? 0);

        return round($sum, 2);
    }

    /**
     * Parent unit price only. Selected options do not modify the parent base price.
     *
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateUnitPrice(ProductService $parent, array $selected = [], bool $forPurchase = false): float
    {
        if (! $forPurchase && $parent->isSalePriceInclusive()) {
            $taxRate = ! empty($parent->tax_id) ? (float) $parent->taxRate($parent->tax_id) : 0.0;
            $inclusive = self::calculateCatalogInclusiveTotal($parent, [], false);
            if ($inclusive > 0 && $taxRate > 0) {
                return round($inclusive / (1 + ($taxRate / 100)), 2);
            }
        }

        return round(
            $forPurchase
                ? $parent->exclusivePurchaseUnitPrice()
                : $parent->exclusiveSaleUnitPrice(),
            2
        );
    }

    /**
     * Independent options money: Σ(option_price × option_qty). Not scaled by parent qty.
     *
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateOptionsAmount(array $selected, bool $forPurchase = false): float
    {
        $sum = 0.0;
        foreach ($selected as $row) {
            $optId = (int) ($row['product_id'] ?? 0);
            $optQty = (float) ($row['quantity'] ?? 1);
            if ($optId <= 0 || $optQty <= 0) {
                continue;
            }
            if (isset($row['price'])) {
                $sum += (float) $row['price'] * $optQty;
            } else {
                $opt = ProductService::find($optId);
                if ($opt) {
                    $p = $forPurchase
                        ? $opt->exclusivePurchaseUnitPrice()
                        : $opt->exclusiveSaleUnitPrice();
                    $sum += $p * $optQty;
                }
            }
        }

        return round($sum, 2);
    }

    /**
     * Catalog (shelf) inclusive amount for selected options only.
     *
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateOptionsCatalogInclusiveAmount(array $selected, bool $forPurchase = false): float
    {
        $sum = 0.0;
        foreach ($selected as $row) {
            $optId = (int) ($row['product_id'] ?? 0);
            $optQty = (float) ($row['quantity'] ?? 1);
            if ($optId <= 0 || $optQty <= 0) {
                continue;
            }
            $opt = ProductService::find($optId);
            if (! $opt) {
                continue;
            }
            $p = $forPurchase
                ? (float) ($opt->purchase_price ?? 0)
                : (float) ($opt->sale_price ?? 0);
            $sum += $p * $optQty;
        }

        return round($sum, 2);
    }

    /**
     * Line extended total: parent_price × parent_qty + Σ(option_price × option_qty).
     *
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateLineExtendedTotal(
        float $parentUnitPrice,
        float $parentQty,
        array $selected,
        bool $forPurchase = false
    ): float {
        $parentQty = max(0, $parentQty);
        $optionsAmount = self::calculateOptionsAmount($selected, $forPurchase);

        return round(($parentUnitPrice * $parentQty) + $optionsAmount, 2);
    }

    /**
     * One label per selected option, for cart and kitchen slips.
     *
     * @param  array<int, array{product_id?: int, quantity?: float, name?: string}>  $selected
     * @return array<int, string>
     */
    public static function optionDisplayLines(array $selected): array
    {
        $lines = [];
        foreach ($selected as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $opt = ProductService::find((int) ($row['product_id'] ?? 0));
                $name = $opt ? trim((string) $opt->name) : '';
            }
            if ($name === '') {
                continue;
            }
            $oq = (float) ($row['quantity'] ?? 1);
            if ($oq <= 0) {
                $oq = 1;
            }
            // Always show the qty (including 1) so kitchen/production slips
            // never hide how many of an option are needed.
            $name .= ' ×' . rtrim(rtrim(number_format($oq, 4, '.', ''), '0'), '.');
            $lines[] = $name;
        }

        return $lines;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $selected
     */
    public static function formatLineName(ProductService $parent, array $selected): string
    {
        $parts = [$parent->name];
        foreach ($selected as $row) {
            $opt = ProductService::find((int) $row['product_id']);
            if (! $opt) {
                continue;
            }
            $label = $opt->name;
            $oq = (float) ($row['quantity'] ?? 1);
            if ($oq <= 0) {
                $oq = 1;
            }
            $label .= ' ×' . rtrim(rtrim(number_format($oq, 4, '.', ''), '0'), '.');
            $parts[] = $label;
        }

        return implode(' — ', $parts);
    }

    public static function shouldSkipParentStock(ProductService $product): bool
    {
        return (bool) ($product->options_enabled ?? false);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $selected
     */
    public static function deductOptionsForSale(
        array $selected,
        float $lineQuantity,
        int $warehouseId,
        int $creatorId,
        string $stockType,
        string $descriptionPrefix,
        int $typeId
    ): void {
        // Option qty is independent of parent line qty; $lineQuantity kept for call-site compatibility.
        if ($selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $deductQty = (float) ($row['quantity'] ?? 1);
            if ($deductQty <= 0) {
                continue;
            }

            // Option SKUs are linked products; match by id first so stock still moves if
            // created_by on the option row differs slightly from the POS actor company id.
            $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first()
                ?: ProductService::where('id', $optId)->first();
            if (! $opt || ! ($opt->affect_stock ?? true)) {
                continue;
            }

            if (($opt->type ?? null) === 'kit') {
                PosKitDeductionService::deductForSale($optId, $deductQty, $warehouseId, $creatorId, $descriptionPrefix, $typeId);

                continue;
            }

            $original = (float) ($opt->quantity ?? 0);
            $newQty = max(0, $original - $deductQty);
            ProductService::where('id', $optId)->update(['quantity' => $newQty]);
            if ($warehouseId > 0) {
                Utility::warehouse_quantity('minus', $deductQty, $optId, $warehouseId);
            }
            Utility::addProductStock($optId, $deductQty, $stockType, $descriptionPrefix, $typeId);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $selected
     */
    public static function restoreOptionsForVoid(
        array $selected,
        float $lineQuantity,
        int $warehouseId,
        int $creatorId,
        string $stockType,
        string $descriptionPrefix,
        int $typeId
    ): void {
        // Option qty is independent of parent line qty; $lineQuantity kept for call-site compatibility.
        if ($selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $restoreQty = (float) ($row['quantity'] ?? 1);
            if ($restoreQty <= 0) {
                continue;
            }

            $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first();
            if (! $opt || ! ($opt->affect_stock ?? true)) {
                continue;
            }

            $original = (float) ($opt->quantity ?? 0);
            ProductService::where('id', $optId)->update(['quantity' => $original + $restoreQty]);
            if ($warehouseId > 0) {
                Utility::warehouse_quantity('plus', $restoreQty, $optId, $warehouseId);
            }
            Utility::addProductStock($optId, $restoreQty, $stockType, $descriptionPrefix, $typeId);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $selected
     */
    public static function addOptionsForPurchase(
        array $selected,
        float $lineQuantity,
        int $warehouseId,
        int $creatorId,
        string $stockType,
        string $description,
        int $typeId
    ): void {
        // Option qty is independent of parent line qty; $lineQuantity kept for call-site compatibility.
        if ($selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $addQty = (float) ($row['quantity'] ?? 1);
            if ($addQty <= 0) {
                continue;
            }

            $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first();
            if (! $opt) {
                continue;
            }

            Utility::total_quantity('plus', $addQty, $optId);
            if ($warehouseId > 0) {
                Utility::addWarehouseStock($optId, $addQty, $warehouseId);
            }
            Utility::addProductStock($optId, $addQty, $stockType, $description, $typeId);
        }
    }

    public static function optionsLineKeySuffix(array $selected): string
    {
        $ids = array_map(fn ($r) => (int) $r['product_id'] . ':' . (float) ($r['quantity'] ?? 1), $selected);
        sort($ids);

        return '_o_' . substr(md5(implode(',', $ids)), 0, 8);
    }

    public static function parseFromRequest(Request $request): array
    {
        $raw = $request->input('selected_options');
        if ($raw === null && $request->filled('selected_options_json')) {
            $raw = $request->input('selected_options_json');
        }

        return self::normalizeSelectedOptions($raw);
    }

    /**
     * POS / invoice-style line: deduct option products only when parent has options_enabled.
     *
     * @param  array<string, mixed>  $lineValue
     */
    public static function tryDeductPosLine(?ProductService $product, array $lineValue, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId): bool
    {
        if (! $product || ! ($product->options_enabled ?? false)) {
            return false;
        }

        $selected = self::normalizeSelectedOptions($lineValue['selected_options'] ?? $lineValue['selected_options_json'] ?? null);
        if ($selected === []) {
            return false;
        }

        $qty = (float) ($lineValue['quantity'] ?? 0);
        $description = $qty . ' ' . __('quantity sold in pos') . ' ' . $posNoFormatted;
        self::deductOptionsForSale($selected, $qty, $warehouseId, $creatorId, 'pos', $description, $posId);

        return true;
    }

    public static function syncProductOptions(ProductService $productService, Request $request): void
    {
        $creatorId = (int) \Auth::user()->creatorId();
        if ($productService->type !== 'product') {
            $productService->options_enabled = false;
            $productService->save();
            ProductOptionItem::where('parent_product_id', $productService->id)->delete();

            return;
        }

        $productService->options_enabled = $request->boolean('options_enabled');
        $productService->save();

        if (! $productService->options_enabled) {
            ProductOptionItem::where('parent_product_id', $productService->id)->delete();

            return;
        }

        $rows = $request->input('product_options', []);
        if (! is_array($rows)) {
            $rows = [];
        }

        $seen = [];
        $sort = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $optionId = (int) ($row['option_product_id'] ?? 0);
            if ($optionId <= 0 || $optionId === (int) $productService->id) {
                continue;
            }
            $opt = ProductService::where('id', $optionId)
                ->where('created_by', $creatorId)
                ->where('type', 'product')
                ->first();
            if (! $opt || ($opt->options_enabled ?? false)) {
                continue;
            }
            if (in_array($optionId, $seen, true)) {
                continue;
            }
            $seen[] = $optionId;

            ProductOptionItem::updateOrCreate(
                [
                    'parent_product_id' => (int) $productService->id,
                    'option_product_id' => $optionId,
                ],
                [
                    'sort_order' => $sort,
                    'created_by' => $creatorId,
                    'option_group' => ($group = trim((string) ($row['option_group'] ?? ''))) !== '' ? $group : null,
                    'is_required' => filter_var($row['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ]
            );
            $sort++;
        }

        if ($seen === []) {
            ProductOptionItem::where('parent_product_id', $productService->id)->delete();
        } else {
            ProductOptionItem::where('parent_product_id', $productService->id)
                ->whereNotIn('option_product_id', $seen)
                ->delete();
        }
    }
}
