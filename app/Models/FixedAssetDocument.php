<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 0 — asset document (plan §5, spec §24).
 *
 * Photo, supplier invoice, warranty, ownership doc, manual,
 * insurance doc, notes. Version history is mandatory: a new
 * version never overwrites the old row.
 */
class FixedAssetDocument extends Model
{
    public const TYPE_PHOTO = 'photo';
    public const TYPE_SUPPLIER_INVOICE = 'supplier_invoice';
    public const TYPE_WARRANTY = 'warranty';
    public const TYPE_OWNERSHIP = 'ownership';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_INSURANCE = 'insurance';
    public const TYPE_NOTE = 'note';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_PHOTO,
        self::TYPE_SUPPLIER_INVOICE,
        self::TYPE_WARRANTY,
        self::TYPE_OWNERSHIP,
        self::TYPE_MANUAL,
        self::TYPE_INSURANCE,
        self::TYPE_NOTE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'type',
        'file_name',
        'file_path',
        'mime_type',
        'size',
        'version',
        'uploaded_by',
        'notes',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
