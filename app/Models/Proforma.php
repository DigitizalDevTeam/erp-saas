<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proforma extends Model
{
    protected $fillable = [
        'proforma_id',
        'quotation_id',
        'customer_id',
        'warehouse_id',
        'proforma_date',
        'category_id',
        'status',
        'converted_invoice_id',
        'is_invoice_converted',
        'created_by',
    ];

    protected $casts = [
        'proforma_date' => 'date',
        'is_invoice_converted' => 'boolean',
    ];

    public function customer()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id');
    }

    public function warehouse()
    {
        return $this->hasOne(warehouse::class, 'id', 'warehouse_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function items()
    {
        return $this->hasMany(ProformaProduct::class, 'proforma_id', 'id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
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
            $discount = isset($product->discount) ? (float) $product->discount : 0;
            $totalTax += ($taxes / 100) * ((($product->price * $product->quantity) - $discount));
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

    /** Shared with quotation-style edit forms */
    public function getQuotationDateAttribute()
    {
        return $this->proforma_date;
    }
}
