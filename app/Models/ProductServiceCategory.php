<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProductServiceCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
        'parent_id',
        'print_zone',
        'show_on_pos',
        'created_by',
    ];

    protected $casts = [
        'show_on_pos' => 'boolean',
    ];

    public static $categoryType = [
        'Product & Service',
        'Income',
        'Expense',
    ];

    public static $catTypes = [
        'product & service' => 'Product & Service',
        'income' => 'Income',
        'expense' => 'Expense',
        'asset'=> 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'costs of good sold' => 'Costs of Goods Sold',
    ];




    public function categories()
    {
        return $this->hasMany('App\Models\Revenue', 'category_id', 'id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'category_id');
    }

    public function bills()
    {
        return $this->hasMany(Bill::class, 'category_id');
    }



    public function incomeCategoryRevenueAmount()
    {
        $year    = date('Y');
        $revenue = $this->hasMany('App\Models\Revenue', 'category_id', 'id')->where('created_by', \Auth::user()->creatorId())->whereRAW('YEAR(date) =?', [$year])->sum('amount');
        $invoices     =  $this->invoices()->with('items')->get()
        ->sum->getTotal();

        $totalIncome = (!empty($revenue) ? $revenue : 0) + (!empty($invoices) ? ($invoices) : 0);

        return $totalIncome;
    }

    public function expenseCategoryAmount()
    {
        $year    = date('Y');
        $payment = $this->hasMany('App\Models\Payment', 'category_id', 'id')
            ->where('created_by', \Auth::user()->creatorId())
            ->whereRAW('YEAR(date) =?', [$year])
            ->sum('amount');

            $bills     =  $this->bills()->with(['accounts','items'])->get()
            ->sum->getTotal();
        $totalExpense = (!empty($payment) ? $payment : 0) + (!empty($bills) ? ($bills) : 0);

        return $totalExpense;

    }

    public static function getallCategories()
    {

        $cat = ProductServiceCategory::select('product_service_categories.*', \DB::raw("COUNT(pu.category_id) product_services"))
            ->leftjoin('product_services as pu','product_service_categories.id' ,'=','pu.category_id')
            ->where('product_service_categories.created_by', '=', Auth::user()->creatorId())
            ->where('product_service_categories.type', 0)
            ->where(function ($q) {
                // POS category buttons only: "Show on POS" off hides the
                // category here; NULL (pre-toggle rows) counts as shown.
                $q->where('product_service_categories.show_on_pos', 1)
                    ->orWhereNull('product_service_categories.show_on_pos');
            })
            ->orderBy('product_service_categories.id', 'DESC')->groupBy('product_service_categories.id')->get();

        return $cat;
    }

    public function chartAccount()
    {
        return $this->hasOne('App\Models\ChartOfAccount', 'id', 'chart_account_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    /**
     * Full hierarchy label: Parent → Subcategory → Sub-subcategory
     *
     * @param  \Illuminate\Support\Collection<int, self>|\Illuminate\Database\Eloquent\Collection<int, self>  $byId
     */
    public function pathLabel($byId): string
    {
        $names = [];
        $c = $this;
        $guard = 0;
        while ($c && $guard++ < 64) {
            array_unshift($names, $c->name);
            $pid = $c->parent_id ? (int) $c->parent_id : null;
            $c = $pid && $byId->has($pid) ? $byId->get($pid) : null;
        }

        return implode(' → ', $names);
    }

    /**
     * All descendant category IDs under $rootId (not including $rootId).
     *
     * @param  \Illuminate\Support\Collection<int, self>|\Illuminate\Database\Eloquent\Collection<int, self>  $all
     * @return array<int, int>
     */
    public static function descendantIds(int $rootId, $all): array
    {
        $out = [];
        $queue = [$rootId];
        while ($queue !== []) {
            $pid = array_shift($queue);
            foreach ($all as $row) {
                if ((int) ($row->parent_id ?? 0) !== $pid) {
                    continue;
                }
                $cid = (int) $row->id;
                if ($cid === $rootId) {
                    continue;
                }
                if (! in_array($cid, $out, true)) {
                    $out[] = $cid;
                    $queue[] = $cid;
                }
            }
        }

        return $out;
    }
}
