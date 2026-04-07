<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductServiceUnit extends Model
{
    protected $fillable = [
        'name',
        'dimension_mode',
        'length_unit',
        'volumetric_divisor',
        'created_by',
    ];

    public function unit(){
        return $this->hasOne('App\Models\ProductService', 'unit_id', 'id');
    }  
}
