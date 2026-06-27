<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'purchase_quotation_id',
        'proforma_id',
        'converted_bill_id',
        'converted_invoice_id',
        'is_invoice_converted',
        'vender_id',
        'warehouse_id',
        'purchase_date',
        'purchase_number',
        'discount_apply',
        'category_id',
        'created_by',
    ];
    public static $statues = [
        'Draft',
        'Sent',
        'Unpaid',
        'Partialy Paid',
        'Paid',
    ];
    public function vender()
    {
        return $this->hasOne('App\Models\Vender', 'id', 'vender_id');
    }

    public function tax()
    {
        return $this->hasOne('App\Models\Tax', 'id', 'tax_id');
    }

    public function items()
    {
        return $this->hasMany('App\Models\PurchaseProduct', 'purchase_id', 'id');
    }

    public function purchaseQuotation()
    {
        return $this->belongsTo(PurchaseQuotation::class, 'purchase_quotation_id');
    }

    public function sourceProforma()
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public function salesInvoice()
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function vendorBill()
    {
        return $this->belongsTo(Bill::class, 'converted_bill_id');
    }
    public function payments()
    {
        return $this->hasMany('App\Models\PurchasePayment', 'purchase_id', 'id');
    }
    public function category()
    {
        return $this->hasOne('App\Models\ProductServiceCategory', 'id', 'category_id');
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
    public function getTotal()
    {

        return ($this->getSubTotal() -$this->getTotalDiscount()) + $this->getTotalTax();
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

            $totalTax += ($taxes / 100) * ($product->price * $product->quantity);
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
    /** Payment method: cash (or other actual payment) - counts towards paid */
    const PAYMENT_METHOD_CASH = 0;
    /** Payment method: credit - does NOT count towards paid; purchase stays unpaid */
    const PAYMENT_METHOD_CREDIT = 1;

    public function getDue()
    {
        $paidTotal = 0;
        foreach ($this->payments as $payment) {
            // Credit payments do not count as paid; only actual payments reduce due
            if ((int) ($payment->payment_method ?? 0) !== self::PAYMENT_METHOD_CREDIT) {
                $paidTotal += $payment->amount;
            }
        }

        return ($this->getTotal() - $paidTotal);
    }

    /** Total amount paid (excluding credit payments) */
    public function getPaid()
    {
        $paid = 0;
        foreach ($this->payments as $payment) {
            if ((int) ($payment->payment_method ?? 0) !== self::PAYMENT_METHOD_CREDIT) {
                $paid += $payment->amount;
            }
        }
        return $paid;
    }
    public function lastPayments()
    {
        return $this->hasOne('App\Models\PurchasePayment', 'id', 'purchase_id');
    }

    public function taxes()
    {
        return $this->hasOne('App\Models\Tax', 'id', 'tax');
    }

    public static function getPurchaseData($month = '')
    {

        if ($month == 'true') {
            $purchaseProducts = \DB::table('purchase_products')
                ->select('purchase_products.id as purchase',
                    \DB::raw('SUM(purchase_products.quantity) as quantity'),
                    \DB::raw('SUM(discount) as total_discount'),
                    \DB::raw('tax as tax'),
                    \DB::raw('SUM(price)  as price'))
                ->leftJoin('purchases', 'purchase_products.purchase_id', 'purchases.id')
                ->where(\DB::raw('MONTH(purchases.created_at)'), '=', [date('m')])
            ->where('purchases.created_by', \Auth::user()->creatorId())
                ->groupBy('purchase')
                ->get()
                ->keyBy('purchase');
        } else {
            $purchaseProducts = \DB::table('purchase_products')
                ->select('purchase_products.id as purchase',
                    \DB::raw('SUM(quantity) as quantity'),
                    \DB::raw('SUM(discount) as total_discount'),
                    \DB::raw('tax as tax'),
                    \DB::raw('SUM(price)  as price'))
                ->leftJoin('purchases', 'purchase_products.purchase_id', 'purchases.id')
            ->where('purchases.created_by', \Auth::user()->creatorId())
                ->groupBy('purchase')
                ->get()
                ->keyBy('purchase');
        }

        $total = 0;


        foreach($purchaseProducts as $purchase)
        {
            $getTaxData = Utility::getTaxData();
            $totalTaxPrice = 0;
            if (!empty($purchase->tax)) {
                foreach (explode(',', $purchase->tax) as $tax) {
                    $taxPrice = \Utility::taxRate($getTaxData[$tax]['rate'], $purchase->price, $purchase->quantity , $purchase->total_discount);
                    $totalTaxPrice += $taxPrice;
                }
            }

            $total += ($purchase->price  * $purchase->quantity) + $totalTaxPrice - $purchase->total_discount;

        }
        return $total;
    }

    public static function totalPurchaseAmount($month = false)
    {

        $purchasedAmount = self::getPurchaseData($month);

        return Auth::user()->priceFormat($purchasedAmount);
    }

    public static function getPurchaseReportChart()
    {
        $purchases = Purchase::whereDate('created_at', '>', Carbon::now()->subDays(10))
                            ->where('created_by', '=', Auth::user()->creatorId())
                            ->orderBy('created_at')->get()->groupBy(
                                function ($val) {
                                    return Carbon::parse($val->created_at)->format('dm');
                                });

        $total = [];
        if (!empty($purchases) && count($purchases) > 0) {
            foreach ($purchases as $day => $onepurchase) {
                $totals = 0;
                foreach ($onepurchase as $purchase) {
                    $totals += $purchase->getTotal();
                }
                $total[$day] = $totals;
            }
        }
        $d = date("d");
        $m = date("m");
        $y = date("Y");

        for ($i = 0; $i <= 9; $i++) {
            $date                      = date('Y-m-d', mktime(0, 0, 0, $m, ($d - $i), $y));
            $purchasesArray['label'][] = $date;
            $date                      = date('dm', strtotime($date));
            $purchasesArray['value'][] = array_key_exists($date, $total) ? $total[$date] : 0;;
        }

        return $purchasesArray;
    }




}
