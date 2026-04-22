<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

class Customer extends Authenticatable
{
    use HasRoles;
    use Notifiable;

    protected $guard_name = 'web';

    protected $fillable = [
        'customer_id',
        'name',
        'email',
        'password',
        'contact',
        'tax_number',
        'avatar',
        'payment_type',
        'credit_terms',
        'price_level_id',
        'is_active',
        'created_by',
        'email_verified_at',
        'billing_name',
        'billing_country',
        'billing_state',
        'billing_city',
        'billing_phone',
        'billing_zip',
        'billing_address',
        'shipping_name',
        'shipping_country',
        'shipping_state',
        'shipping_city',
        'shipping_phone',
        'shipping_zip',
        'shipping_address',
        'contacts' => 'array',
        'bank_account' => 'array',
        'additional_emails' => 'array',
        'kyc' => 'array',
        'notification_email',
        'notification_phone',
        'notify_account_email',
        'notify_account_sms',
        'notify_account_whatsapp',
        'notify_payment_email',
        'notify_payment_sms',
        'notify_payment_whatsapp',
        'notify_invoice_email',
        'notify_invoice_sms',
        'notify_invoice_whatsapp',
    ];

    protected $casts = [
        'notify_account_email' => 'boolean',
        'notify_account_sms' => 'boolean',
        'notify_account_whatsapp' => 'boolean',
        'notify_payment_email' => 'boolean',
        'notify_payment_sms' => 'boolean',
        'notify_payment_whatsapp' => 'boolean',
        'notify_invoice_email' => 'boolean',
        'notify_invoice_sms' => 'boolean',
        'notify_invoice_whatsapp' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];


    public $settings;

    public function operationalEmail(): ?string
    {
        $n = trim((string) ($this->notification_email ?? ''));
        if ($n !== '') {
            return $n;
        }
        $e = trim((string) ($this->email ?? ''));

        return $e !== '' ? $e : null;
    }

    public function operationalPhone(): string
    {
        $p = trim((string) ($this->notification_phone ?? ''));

        return $p !== '' ? $p : (string) ($this->contact ?? '');
    }

    /**
     * @param  'account'|'payment'|'invoice'  $category
     * @param  'email'|'sms'|'whatsapp'  $channel
     */
    public function wantsNotification(string $category, string $channel): bool
    {
        $suffix = match ($channel) {
            'sms' => '_sms',
            'whatsapp' => '_whatsapp',
            default => '_email',
        };
        $attr = match ($category) {
            'account' => 'notify_account' . $suffix,
            'payment' => 'notify_payment' . $suffix,
            'invoice' => 'notify_invoice' . $suffix,
            default => 'notify_invoice_email',
        };

        return (bool) $this->getAttribute($attr);
    }

    public function authId()
    {
        return $this->id;
    }
    
    public function priceLevel()
    {
        return $this->belongsTo(PriceLevel::class);
    }

    public function creatorId()
    {
        if($this->type == 'company' || $this->type == 'super admin')
        {
            return $this->id;
        }
        else
        {
            return $this->created_by;
        }
    }

    public function currentLanguage()
    {
        return $this->lang;
    }

    public function priceFormat($price)
    {
        $settings = Utility::settings();

        return (($settings['site_currency_symbol_position'] == "pre") ? $settings['site_currency_symbol'] : '') . number_format($price, Utility::getValByName('decimal_number')) . (($settings['site_currency_symbol_position'] == "post") ? $settings['site_currency_symbol'] : '');
    }

    public function currencySymbol()
    {
        $settings = Utility::settings();

        return $settings['site_currency_symbol'];
    }

    public function dateFormat($date)
    {
        $settings = Utility::settings();

        return date($settings['site_date_format'], strtotime($date));
    }

    public function timeFormat($time)
    {
        $settings = Utility::settings();

        return date($settings['site_time_format'], strtotime($time));
    }

    public function invoiceNumberFormat($number)
    {
        $settings = Utility::settings();

        return $settings["invoice_prefix"] . sprintf("%05d", $number);
    }

    public function proposalNumberFormat($number)
    {
        $settings = Utility::settings();

        return $settings["proposal_prefix"] . sprintf("%05d", $number);
    }

    public function invoiceChartData()
    {
        $month[]       = __('January');
        $month[]       = __('February');
        $month[]       = __('March');
        $month[]       = __('April');
        $month[]       = __('May');
        $month[]       = __('June');
        $month[]       = __('July');
        $month[]       = __('August');
        $month[]       = __('September');
        $month[]       = __('October');
        $month[]       = __('November');
        $month[]       = __('December');
        $data['month'] = $month;

        $data['currentYear'] = date('M-Y');

        $totalInvoice = Invoice::where('customer_id', \Auth::user()->id)->count();
        $unpaidArr    = array();


        for($i = 1; $i <= 12; $i++)
        {
            $unpaidInvoice  = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->whereRaw('month(`send_date`) = ?', $i)->where('status', '1')->where('due_date', '>', date('Y-m-d'))->get();
            $paidInvoice    = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->whereRaw('month(`send_date`) = ?', $i)->where('status', '4')->get();
            $partialInvoice = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->whereRaw('month(`send_date`) = ?', $i)->where('status', '3')->get();
            $dueInvoice     = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->whereRaw('month(`send_date`) = ?', $i)->where('status', '1')->where('due_date', '<', date('Y-m-d'))->get();


            $totalUnpaid = 0;
            for($j = 0; $j < count($unpaidInvoice); $j++)
            {
                $unpaidAmount = $unpaidInvoice[$j]->getDue();
                $totalUnpaid  += $unpaidAmount;

            }

            $totalPaid = 0;
            for($j = 0; $j < count($paidInvoice); $j++)
            {
                $paidAmount = $paidInvoice[$j]->getTotal();
                $totalPaid  += $paidAmount;

            }

            $totalPartial = 0;
            for($j = 0; $j < count($partialInvoice); $j++)
            {
                $partialAmount = $partialInvoice[$j]->getDue();
                $totalPartial  += $partialAmount;

            }

            $totalDue = 0;
            for($j = 0; $j < count($dueInvoice); $j++)
            {
                $dueAmount = $dueInvoice[$j]->getDue();
                $totalDue  += $dueAmount;

            }

            $unpaidData[]  = $totalUnpaid;
            $paidData[]    = $totalPaid;
            $partialData[] = $totalPartial;
            $dueData[]     = $totalDue;

            $statusData['unpaid']  = $unpaidData;
            $statusData['paid']    = $paidData;
            $statusData['partial'] = $partialData;
            $statusData['due']     = $dueData;
        }

        $data['data'] = $statusData;


        $unpaidInvoice  = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->where('status', '1')->where('due_date', '>', date('Y-m-d'))->get();
        $paidInvoice    = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->where('status', '4')->get();
        $partialInvoice = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->where('status', '3')->get();
        $dueInvoice     = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->where('status', '1')->where('due_date', '<', date('Y-m-d'))->get();

        $progressData['totalInvoice']        = $totalInvoice = Invoice:: where('customer_id', \Auth::user()->id)->whereRaw('year(`send_date`) = ?', array(date('Y')))->count();
        $progressData['totalUnpaidInvoice']  = $totalUnpaidInvoice = count($unpaidInvoice);
        $progressData['totalPaidInvoice']    = $totalPaidInvoice = count($paidInvoice);
        $progressData['totalPartialInvoice'] = $totalPartialInvoice = count($partialInvoice);
        $progressData['totalDueInvoice']     = $totalDueInvoice = count($dueInvoice);

        $progressData['unpaidPr']  = ($totalInvoice != 0) ? ($totalUnpaidInvoice * 100) / $totalInvoice : 0;
        $progressData['paidPr']    = ($totalInvoice != 0) ? ($totalPaidInvoice * 100) / $totalInvoice : 0;
        $progressData['partialPr'] = ($totalInvoice != 0) ? ($totalPartialInvoice * 100) / $totalInvoice : 0;
        $progressData['duePr']     = ($totalInvoice != 0) ? ($totalDueInvoice * 100) / $totalInvoice : 0;

        $progressData['unpaidColor']  = '#fc544b';
        $progressData['paidColor']    = '#63ed7a';
        $progressData['partialColor'] = '#6777ef';
        $progressData['dueColor']     = '#ffa426';

        $data['progressData'] = $progressData;


        return $data;
    }


    public function customerInvoice($customerId)
    {
        $invoices  = Invoice:: where('customer_id', $customerId)->orderBy('issue_date', 'desc')->get();

        return $invoices;
    }

    public function customerProposal($customerId)
    {
        $proposals = Proposal:: where('customer_id', $customerId)->orderBy('issue_date', 'desc')->get();

        return $proposals;
    }

    public function customerOverdue($customerId)
    {
        $dueInvoices = Invoice:: where('customer_id', $customerId)->whereNotIn(
            'status', [
                        '0',
                        '4',
                    ]
        )->where('due_date', '<', date('Y-m-d'))->get();
        $due         = 0;
        foreach($dueInvoices as $invoice)
        {
            $due += $invoice->getDue();
        }

        return $due;
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    public function customerTotalInvoiceSum($customerId)
    {
        return $this->invoices()
            ->with('items')
            ->get()
            ->sum->getTotal();
    }

    public function customerTotalInvoice($customerId)
    {
        $invoices = Invoice:: where('customer_id', $customerId)->count();

        return $invoices;
    }

    public static function customer_id($customer_name)
    {
        $customer = DB::table('customers')
        ->where('name', $customer_name)
        ->where('created_by', Auth::user()->creatorId())
        ->select('id')
        ->first();

        return ($customer != null) ? $customer->id : 0;
    }

    /**
     * Total outstanding (receivable) for customer: sum of getDue() on all unpaid/partial invoices.
     */
    public static function totalOutstanding($customerId, ?int $companyId = null)
    {
        if (!$customerId) {
            return 0;
        }
        $query = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['0', '1', '2', '3'])
            ->with('payments');
        if ($companyId !== null) {
            $query->where('created_by', $companyId);
        }
        $invoices = $query->get();
        $total = 0;
        foreach ($invoices as $inv) {
            $total += $inv->getDue();
        }
        return round($total, 2);
    }


    public function billingAddresses()
    {
        return $this->hasMany(CustomerBillingAddress::class, 'customer_id', 'id');
    }

    public function shippingAddresses()
    {
        return $this->hasMany(CustomerShippingAddress::class, 'customer_id', 'id');
    }
}
