<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IrpsWithholdingRow extends Model
{
    protected $fillable = [
        'salary_from',
        'salary_to',
        'base_dep0',
        'base_dep1',
        'base_dep2',
        'base_dep3',
        'base_dep4plus',
        'coefficient',
        'created_by',
    ];
}

