<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IrpsBracket extends Model
{
    protected $fillable = [
        'salary_from',
        'salary_to',
        'dependents',
        'rate_percent',
        'deduction',
        'fixed',
        'created_by',
    ];
}

