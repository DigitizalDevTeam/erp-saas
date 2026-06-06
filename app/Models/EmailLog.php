<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'created_by',
        'user_id',
        'recipient',
        'subject',
        'source',
        'mailable_class',
        'status',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}
