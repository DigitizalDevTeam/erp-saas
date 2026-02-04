<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    protected $fillable = [
        'name',
        'name_fr',
        'name_zh',
        'name_es',
        'name_pt_br',
        'code',
        'type',
        'sub_type',
        'parent',
        'is_enabled',
        'description',
        'description_fr',
        'description_zh',
        'description_es',
        'description_pt_br',
        'created_by',
    ];

    public function types()
    {
        return $this->hasOne('App\Models\ChartOfAccountType', 'id', 'type');
    }

    public function accounts()
    {
        return $this->hasOne('App\Models\JournalItem', 'account', 'id');
    }

    public function balance()
    {
        $journalItem         = JournalItem::select(\DB::raw('sum(credit) as totalCredit'),
            \DB::raw('sum(debit) as totalDebit'),
            \DB::raw('sum(credit) - sum(debit) as netAmount'))->where('account', $this->id);
        $journalItem         = $journalItem->first();
        $data['totalCredit'] = $journalItem->totalCredit;
        $data['totalDebit']  = $journalItem->totalDebit;
        $data['netAmount']   = $journalItem->netAmount;

        return $data;
    }

    public function subType()
    {
        return $this->hasOne('App\Models\ChartOfAccountSubType', 'id', 'sub_type');
    }

    public function parentAccount()
    {
        return $this->hasOne('App\Models\ChartOfAccountParent', 'id', 'parent');
    }
}
