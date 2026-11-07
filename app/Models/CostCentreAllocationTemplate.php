<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreAllocationTemplate extends Model
{
    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'basis',
        'lines',
    ];

    protected $casts = [
        'lines' => 'array',
    ];

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
