<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccountParent extends Model
{
    protected $fillable = [
        'name',
        'name_fr',
        'name_zh',
        'name_es',
        'name_pt_br',
        'sub_type',
        'type',
        'account',
        'created_by',
    ];
    
    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent', 'id');
    }
    public function types()
    {
        return $this->hasOne('App\Models\ChartOfAccountType', 'id', 'type');
    }
    public function subType()
    {
        return $this->hasOne('App\Models\ChartOfAccountSubType', 'id', 'sub_type');
    }

    public function accountMain()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account');
    }

}
