<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentVoidLog extends Model
{
    public const TYPE_INVOICE = 'invoice';

    public const TYPE_BILL = 'bill';

    public const TYPE_POS = 'pos';

    public const TYPE_POS_LINE = 'pos_line';

    protected $fillable = [
        'created_by',
        'user_id',
        'document_type',
        'document_id',
        'document_number',
        'reason',
        'warehouse_id',
        'stock_restored',
        'accounting_note_created',
        'accounting_note_id',
        'accounting_note_type',
        'meta',
    ];

    protected $casts = [
        'stock_restored' => 'boolean',
        'accounting_note_created' => 'boolean',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
