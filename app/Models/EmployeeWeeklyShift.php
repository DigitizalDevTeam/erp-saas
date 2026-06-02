<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeWeeklyShift extends Model
{
    protected $fillable = [
        'employee_id',
        'day_of_week',
        'shift_code',
        'start_time',
        'end_time',
        'crosses_midnight',
        'created_by',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'day_of_week' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
