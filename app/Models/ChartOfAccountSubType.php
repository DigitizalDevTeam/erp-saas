<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccountSubType extends Model
{
    protected $fillable = [
        'name',
        'name_fr',
        'name_zh',
        'name_es',
        'name_pt_br',
        'type',
        'created_by',
    ];
}
