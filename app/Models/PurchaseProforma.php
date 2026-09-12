<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseProforma extends Model
{
    protected $fillable = [
        'purchase_proforma_id',
        'vender_id',
        'warehouse_id',
        'proforma_date',
        'category_id',
        'status',
        'purchase_quotation_id',
        'converted_purchase_id',
        'is_converted',
        'created_by',
    ];

    protected $casts = [
        'proforma_date' => 'date',
        'is_converted' => 'boolean',
    ];

    public function vender()
    {
        return $this->hasOne(Vender::class, 'id', 'vender_id');
    }

    public function warehouse()
    {
        return $this->hasOne(warehouse::class, 'id', 'warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseProformaProduct::class, 'purchase_proforma_id', 'id');
    }

    public function purchaseQuotation()
    {
        return $this->belongsTo(PurchaseQuotation::class, 'purchase_quotation_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'converted_purchase_id');
    }

    /** Alias for shared edit blades that expect quotation_date. */
    public function getQuotationDateAttribute()
    {
        return $this->proforma_date;
    }

    public function getSubTotal()
    {
        $subTotal = 0;
        foreach ($this->items as $product) {
            $subTotal += ($product->price * $product->quantity);
        }

        return $subTotal;
    }

    public function getTotalTax()
    {
        $taxData = Utility::getTaxData();
        $totalTax = 0;
        foreach ($this->items as $product) {
            $taxArr = explode(',', (string) $product->tax);
            $taxes = 0;
            foreach ($taxArr as $tax) {
                $taxes += ! empty($taxData[$tax]['rate']) ? $taxData[$tax]['rate'] : 0;
            }
            $totalTax += ($taxes / 100) * ($product->price * $product->quantity);
        }

        return $totalTax;
    }

    public function getTotalDiscount()
    {
        $totalDiscount = 0;
        foreach ($this->items as $product) {
            $totalDiscount += (float) $product->discount;
        }

        return $totalDiscount;
    }

    public function getTotal()
    {
        return ($this->getSubTotal() - $this->getTotalDiscount()) + $this->getTotalTax();
    }
}
