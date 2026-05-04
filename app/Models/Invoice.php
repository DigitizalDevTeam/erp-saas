<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_id',
        'pos_id',
        'quotation_id',
        'proforma_id',
        'customer_id',
        'issue_date',
        'due_date',
        'ref_number',
        'status',
        'category_id',
        'created_by',
    ];

    public function pos()
    {
        return $this->belongsTo(Pos::class, 'pos_id');
    }

    public function sourceQuotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function sourceProforma()
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public static $statues = [
        'Draft',
        'Sent',
        'Unpaid',
        'Partialy Paid',
        'Paid',
        'Cancelled',
    ];


    public function tax()
    {
        return $this->hasOne('App\Models\Tax', 'id', 'tax_id');
    }

    public function items()
    {
        return $this->hasMany('App\Models\InvoiceProduct', 'invoice_id', 'id');
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class, 'invoice_id');
    }

    public function payments()
    {
        return $this->hasMany('App\Models\InvoicePayment', 'invoice_id', 'id');
    }
    public function bankPayments()
    {
        return $this->hasMany('App\Models\InvoiceBankTransfer', 'invoice_id', 'id')->where('status','!=','Approved');
    }
    public function customer()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'customer_id');
    }
    public function customer_data()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'customer_id');
    }
    public function billing_address()
    {
        return $this->hasOne('App\Models\CustomerBillingAddress', 'id', 'billing_address_id');
    }
    public function shipping_address()
    {
        return $this->hasOne('App\Models\CustomerShippingAddress', 'id', 'shipping_address_id');
    }






    public function getTotal()
    {

        return ($this->getSubTotal() -$this->getTotalDiscount()) + $this->getTotalTax();
    }

    public function getSubTotal()
    {
        $subTotal = 0;
        foreach($this->items as $product)
        {

            $subTotal += ($product->price * $product->quantity);
        }

        return $subTotal;
    }



    public function getTotalTax()
    {
        $taxData = Utility::getTaxData();
        $totalTax = 0;
        foreach($this->items as $product)
        {
            // $taxes = Utility::totalTaxRate($product->tax);

            $taxArr = explode(',', $product->tax);
            $taxes = 0;
            foreach ($taxArr as $tax) {
                // $tax = TaxRate::find($tax);
                $taxes += !empty($taxData[$tax]['rate']) ? $taxData[$tax]['rate'] : 0;
            }

            $discount = isset($product->discount) ? $product->discount : 0;
            $totalTax += ($taxes / 100) * (($product->price - $discount) * $product->quantity);
        }

        return $totalTax;
    }
    public function getTotalDiscount()
    {
        $totalDiscount = 0;
        foreach($this->items as $product)
        {
            $totalDiscount += $product->discount;
        }

        return $totalDiscount;
    }

    public function getDue()
    {
        $due = 0;
        foreach($this->payments as $payment)
        {
            $due += $payment->amount;
        }

        return ($this->getTotal() - $due) - $this->invoiceTotalCreditNote() + $this->invoiceTotalDebitNote();
    }

    public static function change_status($invoice_id, $status)
    {

        $invoice         = Invoice::find($invoice_id);
        $invoice->status = $status;
        $invoice->update();
    }

    public function category()
    {
        return $this->hasOne('App\Models\ProductServiceCategory', 'id', 'category_id');
    }

    public function creditNote()
    {

        return $this->hasMany('App\Models\CreditNote', 'invoice', 'id')->where('is_debit', 0);
    }
    
    public function salesDebitNote()
    {

        return $this->hasMany('App\Models\CreditNote', 'invoice', 'id')->where('is_debit', 1);
    }

    public function invoiceTotalCreditNote()
    {
        return $this->creditNote->sum('amount');
    }
    
    public function invoiceTotalDebitNote()
    {
        return $this->salesDebitNote->sum('amount');
    }

    public function lastPayments()
    {
        return $this->hasOne('App\Models\InvoicePayment', 'invoice_id', 'id')->latest('created_at');
    }

    public function taxes()
    {
        return $this->hasOne('App\Models\Tax', 'id', 'tax');
    }

    public function products()
    {
        return $this->hasMany(InvoiceProduct::class);
    }
}
