<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosPrinterAgentPairing extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_code',
        'created_by',
        'branch_id',
        'machine_name',
        'paired_at',
    ];

    protected $casts = [
        'paired_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function isPaired(): bool
    {
        return $this->branch_id !== null && $this->paired_at !== null;
    }
}
