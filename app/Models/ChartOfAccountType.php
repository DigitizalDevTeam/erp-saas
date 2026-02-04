<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccountType extends Model
{
    protected $fillable = [
        'name',
        'name_fr',
        'name_zh',
        'name_es',
        'name_pt_br',
        'created_by',
    ];

}
