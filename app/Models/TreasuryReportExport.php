<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreasuryReportExport extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    public const FORMATS = ['xlsx', 'pdf'];

    /** Downloads stay valid for this long after the request. */
    public const EXPIRY_HOURS = 24;

    protected $fillable = [
        'created_by',
        'report',
        'format',
        'filters',
        'file_path',
        'token',
        'status',
        'error_message',
        'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'expires_at' => 'datetime',
    ];

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
