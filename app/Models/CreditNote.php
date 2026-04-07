<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $fillable = [
        'invoice',
        'customer',
        'amount',
        'date',
    ];

    // public function customer()
    // {
    //     return $this->hasOne('App\Models\Customer', 'customer_id', 'customer');
    // }
    
    public function customerRelation()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'customer');
    }
    public function invoice_data()
    {
        return $this->hasOne('App\Models\Invoice', 'id', 'invoice');
    }
}
