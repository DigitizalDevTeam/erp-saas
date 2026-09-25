<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosBranchPrintConfig extends Model
{
    protected $fillable = [
        'branch_id',
        'created_by',
        'kitchen_printer_id',
        'bar_printer_id',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function kitchenPrinter()
    {
        return $this->belongsTo(PosAgentPrinter::class, 'kitchen_printer_id');
    }

    public function barPrinter()
    {
        return $this->belongsTo(PosAgentPrinter::class, 'bar_printer_id');
    }
}
