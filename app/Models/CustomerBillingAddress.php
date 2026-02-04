<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBillingAddress extends Model
{
    protected $table = 'customer_billing_addresses';

    protected $fillable = [
        'customer_id', 'name', 'country', 'state', 'city', 'phone', 'zip', 'address'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
