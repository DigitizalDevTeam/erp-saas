<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_service_id',
        'size',
        'color',
        'sku',
        'sale_price',
        'purchase_price',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_service_id');
    }

    public function displayLabel(ProductService $product): string
    {
        $parts = [];
        if ($product->variant_size_label && (string) $this->size !== '') {
            $parts[] = $product->variant_size_label . ': ' . $this->size;
        } elseif ((string) $this->size !== '') {
            $parts[] = $this->size;
        }
        if ($product->variant_color_label && (string) $this->color !== '') {
            $parts[] = $product->variant_color_label . ': ' . $this->color;
        } elseif ((string) $this->color !== '') {
            $parts[] = $this->color;
        }

        return $parts === [] ? $this->sku : implode(' · ', $parts);
    }
}
