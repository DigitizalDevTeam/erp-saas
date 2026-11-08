<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_id',
        'pos_id',
        'document_type',
        'quotation_id',
        'proforma_id',
        'purchase_id',
        'customer_id',
        'issue_date',
        'due_date',
        'ref_number',
        'client_po_number',
        'client_po_date',
        'client_po_details',
        'quotation_reference',
        'status',
        'credit_hold_reasons',
        'authorized_by',
        'authorized_at',
        'category_id',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    public const STATUS_DRAFT = 0;
    public const STATUS_SENT = 1;
    public const STATUS_UNPAID = 2;
    public const STATUS_PARTIALLY_PAID = 3;
    public const STATUS_PAID = 4;
    public const STATUS_CANCELLED = 5;
    public const STATUS_PENDING_AUTHORIZATION = 6;

    /**
     * Internal Consumption invoice (option B): created from an
     * internal-consumption POS order, numbered in the separate
     * #INT series and posted to expense account 611 — never a sale.
     * Old internal invoices (created before the series existed)
     * keep document_type NULL and stay in the sales series.
     */
    public function isInternal(): bool
    {
        return ($this->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION;
    }

    /**
     * Display number: #INT + 5 digits for internal invoices (the
     * prefix comes from the pos_internal_prefix setting, default
     * #INT — shared with POS numbering), otherwise the normal
     * invoice prefix. The two series share the invoice_id space
     * (invoice_id is NOT unique), so the prefix is what makes a
     * number unambiguous.
     */
    public function displayNumber(): string
    {
        $settings = Utility::settingsById($this->created_by);

        if ($this->isInternal()) {
            $prefix = $settings['pos_internal_prefix'] ?? '#INT';

            return ($prefix !== '' ? $prefix : '#INT') . sprintf('%05d', (int) $this->invoice_id);
        }

        return Utility::invoiceNumberFormat($settings, (int) $this->invoice_id);
    }

    protected $casts = [
        'voided_at' => 'datetime',
        'authorized_at' => 'datetime',
        'client_po_date' => 'date',
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

    public function sourcePurchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public static $statues = [
        'Draft',
        'Sent',
        'Unpaid',
        'Partialy Paid',
        'Paid',
        'Cancelled',
        'Pending Authorization',
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
        // Internal Consumption invoices are settled by the
        // consumption itself: no payment is ever owed on them.
        if ($this->isInternal()) {
            return 0;
        }

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
