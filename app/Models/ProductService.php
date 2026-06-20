<?php

namespace App\Models;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductService extends Model
{
    protected $fillable = [
        'name',
        'sku',
        'shelf_no',
        'row_no',
        'sale_price',
        'sale_price_type',
        'purchase_price',
        'tax_id',
        'price_level_id',
        'category_id',
        'unit_id',
        'unit_type',
        'type',
        'print_zone',
        'pos_printer_id',
        'low_stock_threshold',
        'max_stock_threshold',
        'affect_stock',
        'identifier_tracking',
        'identifier_type',
        'warranty_days',
        'expiry_alert_days',
        'batch_tracking',
        'batch_consumption_method',
        'variants_enabled',
        'options_enabled',
        'variant_size_label',
        'variant_color_label',
        'unit_volume_cbm',
        'unit_area_sqm',
        'unit_length',
        'unit_width',
        'unit_height',
        'dimension_unit',
        'volumetric_divisor',
        'unit_volumetric_weight',
        'last_min_stock_alert_at',
        'last_max_stock_alert_at',
        'sale_chartaccount_id',
        'expense_chartaccount_id',
        'created_by',
    ];

    protected $casts = [
        'affect_stock' => 'boolean',
        'identifier_tracking' => 'boolean',
        'batch_tracking' => 'boolean',
        'variants_enabled' => 'boolean',
        'options_enabled' => 'boolean',
        'last_min_stock_alert_at' => 'datetime',
        'last_max_stock_alert_at' => 'datetime',
    ];

    public function taxes()
    {
        return $this->hasOne('App\Models\Tax', 'id', 'tax_id')->first();
    }
    
    public function priceLevel()
    {
        return $this->belongsTo(PriceLevel::class);
    }

    public function unit()
    {
        return $this->hasOne('App\Models\ProductServiceUnit', 'id', 'unit_id');
    }

    public function category()
    {
        return $this->hasOne('App\Models\ProductServiceCategory', 'id', 'category_id');
    }

    public function posPrinter()
    {
        return $this->belongsTo(\App\Models\PosPrinter::class, 'pos_printer_id', 'id');
    }

    /** Phase 5: Recipe (BOM) – ingredients for this product. If not empty, POS deducts by recipe. */
    public function recipes()
    {
        return $this->hasMany(ProductRecipe::class, 'product_id', 'id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'product_service_id')->orderBy('sort_order')->orderBy('id');
    }

    public function optionItems()
    {
        return $this->hasMany(ProductOptionItem::class, 'parent_product_id')->orderBy('sort_order')->orderBy('id');
    }

    public function variantStockInWarehouse(?int $warehouseId, ?int $variantId): float
    {
        $q = WarehouseProduct::where('product_id', $this->id);
        if ($warehouseId !== null && $warehouseId > 0) {
            $q->where('warehouse_id', $warehouseId);
        }
        if ($variantId !== null && $variantId > 0) {
            $q->where('product_variant_id', $variantId);
        } else {
            $q->whereNull('product_variant_id');
        }

        return (float) $q->sum('quantity');
    }

    public function warehouse()
    {
        return $this->hasOne('App\Models\Warehouse', 'id', 'category_id');
    }

    public function tax($taxes)
    {
        $taxArr = explode(',', $taxes);

        $taxes  = [];
        foreach($taxArr as $tax)
        {
            $taxes[] = Tax::find($tax);
        }

        return $taxes;
    }

    public function taxRate($taxes)
    {
        $taxArr  = explode(',', $taxes);
        $taxRate = 0;
        foreach($taxArr as $tax)
        {
            $tax     = Tax::find($tax);
            $taxRate += $tax->rate;
        }

        return $taxRate;
    }

    public static function taxData($taxes)
    {
        $taxArr = explode(',', $taxes);

        $taxes = [];
        foreach($taxArr as $tax)
        {
            $taxesData = Tax::find($tax);
            $taxes[]   = !empty($taxesData) ? $taxesData->name : '';
        }

        return implode(',', $taxes);
    }

    /** Products that may appear on POS (excludes ingredient-only stock items). */
    public function scopeSellableOnPos($query)
    {
        return $query->whereIn('product_services.type', ['product', 'kit']);
    }

    public function isIngredient(): bool
    {
        return ($this->type ?? '') === 'ingredient';
    }

    public static function getallproducts()
    {
        return ProductService::select('product_services.*', 'c.name as categoryname')
            ->sellableOnPos()
            ->leftjoin('product_service_categories as c', 'c.id', '=', 'product_services.category_id')
            ->where('product_services.created_by', '=', Auth::user()->creatorId())
            ->orderBy('product_services.id', 'DESC');
    }

    /**
     * Lean query for POS product list: no join, only columns needed for grid.
     */
    public function scopePosList($query)
    {
        return $query->select(
            'product_services.id',
            'product_services.name',
            'product_services.sku',
            'product_services.sale_price',
            'product_services.purchase_price',
            'product_services.quantity',
            'product_services.tax_id',
            'product_services.pro_image',
            'product_services.unit_id',
            'product_services.category_id',
            'product_services.print_zone',
            'product_services.type',
            'product_services.affect_stock',
            'product_services.variants_enabled'
        )
            ->sellableOnPos()
            ->where('product_services.created_by', Auth::user()->creatorId())
            ->orderBy('product_services.id', 'DESC');
    }

    public function getTotalProductQuantity()
    {
        $totalquantity = $purchasedquantity = $posquantity = 0;
        $authuser = Auth::user();
        $product_id = $this->id;
        $purchases = Purchase::where('created_by', $authuser->creatorId());

        if ($authuser->isUser())
        {
            $purchases = $purchases->where('warehouse_id', $authuser->warehouse_id);
        }

        foreach($purchases->get() as $purchase)
        {
            $purchaseditem = PurchaseProduct::select('quantity')->where('purchase_id', $purchase->id)->where('product_id', $product_id)->first();

            $purchasedquantity += $purchaseditem != null ? $purchaseditem->quantity : 0;

        }

        $poses = Pos::where('created_by', $authuser->creatorId());

        if ($authuser->isUser())
        {
            $pos = $poses->where('warehouse_id', $authuser->warehouse_id);
        }

        foreach($poses->get() as $pos)
        {
            $positem = PosProduct::select('quantity')->where('pos_id', $pos->id)->where('product_id', $product_id)->first();
            $posquantity += $positem != null ? $positem->quantity : 0;
        }

        $totalquantity = $purchasedquantity - $posquantity;


        return $totalquantity;
    }


    public function getQuantity()
    {
        $totalquantity = $purchasedquantity = $quotationquantity = 0;
        $authuser = Auth::user();
        $product_id = $this->id;
        $purchases = Purchase::where('created_by', $authuser->creatorId());

        if ($authuser->isUser())
        {
            $purchases = $purchases->where('warehouse_id', $authuser->warehouse_id);
        }

        foreach($purchases->get() as $purchase)
        {
            $purchaseditem = PurchaseProduct::select('quantity')->where('purchase_id', $purchase->id)->where('product_id', $product_id)->first();

            $purchasedquantity += $purchaseditem != null ? $purchaseditem->quantity : 0;

        }

        $quotations = Quotation::where('created_by', $authuser->creatorId());

        if ($authuser->isUser())
        {
            $quotation = $quotations->where('warehouse_id', $authuser->warehouse_id);
        }

        foreach($quotations->get() as $quotation)
        {
            $quotationitem = QuotationProduct::select('quantity')->where('quotation_id', $quotation->id)->where('product_id', $product_id)->first();
            $quotationquantity += $quotationitem != null ? $quotationitem->quantity : 0;
        }

        $totalquantity = $purchasedquantity - $quotationquantity;


        return $totalquantity;
    }

    public static function tax_id($product_id)
    {
        $tax = DB::table('product_services')
        ->where('id', $product_id)
        ->where('created_by', Auth::user()->creatorId())
        ->select('tax_id')
        ->first();

        return ($tax != null) ? $tax->tax_id : 0;
    }

    public function warehouseProduct($product_id,$warehouse_id)
    {

        $product=WarehouseProduct::where('warehouse_id',$warehouse_id)->where('product_id',$product_id)->first();

        return !empty($product)?$product->quantity:0;
    }



}
