<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLayout extends Model
{
    public const TYPES = ['invoice', 'bill', 'purchase', 'quotation', 'pos'];

    protected $fillable = [
        'created_by',
        'document_type',
        'name',
        'is_active',
        'page_width',
        'page_height',
        'elements',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'elements' => 'array',
        'page_width' => 'integer',
        'page_height' => 'integer',
    ];

    public static function activeFor(string $documentType, int $createdBy): ?self
    {
        return static::query()
            ->where('created_by', $createdBy)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->first();
    }
}
