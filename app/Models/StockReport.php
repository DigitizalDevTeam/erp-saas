<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'type',
        'type_id',
        'warehouse_loss_id',
        'description',
    ];

    public function warehouseLoss()
    {
        return $this->belongsTo(WarehouseLoss::class, 'warehouse_loss_id');
    }


    public function product()
    {
        return $this->hasOne('App\Models\ProductService', 'id', 'product_id')->withTrashed();
    }


    //for export
    public static function products($product)
    {
        $categoryArr  = explode(',', $product);

        foreach ($categoryArr as $product)
        {
            $product    = ProductService::find($product);
            $categoryArr = isset($product) ? $product->name : "";
        }
        return $categoryArr;
    }



}
