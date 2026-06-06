<?php

namespace App\Services;

use App\Models\ProductOptionItem;
use App\Models\ProductService;
use App\Models\Utility;
use Illuminate\Http\Request;

/**
 * Parent product with options: sale price = parent + sum(selected option prices).
 * Stock moves on selected option products only (not parent).
 */
class ProductOptionService
{
    /**
     * @return array<int, array{product_id: int, quantity: float, price: float}>
     */
    public static function normalizeSelectedOptions(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
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
            $productId = (int) ($row['product_id'] ?? 0);
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
     * @return array{options_enabled: bool, options: array<int, array<string, mixed>>}
     */
    public static function productJsonExtras(ProductService $product): array
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
            : ProductService::whereIn('id', $optionIds)->where('created_by', $product->created_by)->get()->keyBy('id');

        $options = [];
        foreach ($items as $item) {
            $op = $products->get($item->option_product_id);
            if (! $op || ($op->type ?? '') !== 'product') {
                continue;
            }
            if ((bool) ($op->options_enabled ?? false)) {
                continue;
            }
            $options[] = [
                'product_id' => (int) $op->id,
                'name' => $op->name,
                'sku' => $op->sku,
                'sale_price' => (float) ($op->sale_price ?? 0),
                'purchase_price' => (float) ($op->purchase_price ?? 0),
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
            throw new \RuntimeException(__('Select at least one product option.'));
        }

        $allowed = ProductOptionItem::query()
            ->where('parent_product_id', $parentId)
            ->where('created_by', $creatorId)
            ->pluck('option_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

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
                : round((float) ($forPurchase ? $opt->purchase_price : $opt->sale_price), 2);
            $validated[] = [
                'product_id' => $optId,
                'quantity' => (float) $row['quantity'],
                'price' => $price,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price?: float}>  $selected
     */
    public static function calculateUnitPrice(ProductService $parent, array $selected, bool $forPurchase = false): float
    {
        $base = $forPurchase
            ? (float) ($parent->purchase_price ?? 0)
            : (float) ($parent->sale_price ?? 0);

        $sum = 0.0;
        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $optQty = (float) ($row['quantity'] ?? 1);
            if (isset($row['price'])) {
                $sum += (float) $row['price'] * $optQty;
            } else {
                $opt = ProductService::find($optId);
                if ($opt) {
                    $p = $forPurchase ? (float) ($opt->purchase_price ?? 0) : (float) ($opt->sale_price ?? 0);
                    $sum += $p * $optQty;
                }
            }
        }

        return round($base + $sum, 2);
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
            if ($oq > 1) {
                $label .= ' ×' . rtrim(rtrim(number_format($oq, 4, '.', ''), '0'), '.');
            }
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
        if ($lineQuantity <= 0 || $selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $deductQty = (float) ($row['quantity'] ?? 1) * $lineQuantity;
            if ($deductQty <= 0) {
                continue;
            }

            $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first();
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
        if ($lineQuantity <= 0 || $selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $restoreQty = (float) ($row['quantity'] ?? 1) * $lineQuantity;
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
        if ($lineQuantity <= 0 || $selected === []) {
            return;
        }

        foreach ($selected as $row) {
            $optId = (int) $row['product_id'];
            $addQty = (float) ($row['quantity'] ?? 1) * $lineQuantity;
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
