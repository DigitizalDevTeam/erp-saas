<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceLevel extends Model
{
    protected $fillable = [
        'name', 'type', 'value', 'created_by'
    ];
    
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
    public function venders()
    {
        return $this->hasMany(Vender::class);
    }
    public function products()
    {
        return $this->hasMany(ProductService::class);
    }
}
