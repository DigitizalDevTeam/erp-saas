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
        'base_template',
        'print_template_key',
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

    public static function forPrintKey(string $documentType, int $createdBy, string $printTemplateKey): ?self
    {
        if ($printTemplateKey === '') {
            return null;
        }

        return static::query()
            ->where('created_by', $createdBy)
            ->where('document_type', $documentType)
            ->where('print_template_key', $printTemplateKey)
            ->first();
    }

    public static function forBaseTemplate(string $documentType, int $createdBy, string $baseTemplate): ?self
    {
        if ($baseTemplate === '') {
            return null;
        }

        // Prefer exact print_template_key match (canonical override for that Print Settings key)
        $byKey = static::forPrintKey($documentType, $createdBy, $baseTemplate);
        if ($byKey) {
            return $byKey;
        }

        return static::query()
            ->where('created_by', $createdBy)
            ->where('document_type', $documentType)
            ->where('base_template', $baseTemplate)
            ->where(function ($q) {
                $q->whereNull('print_template_key')
                    ->orWhere('print_template_key', '')
                    ->orWhere('print_template_key', 'not like', 'layout_%');
            })
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->first();
    }
}
