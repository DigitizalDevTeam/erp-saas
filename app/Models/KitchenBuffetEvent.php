<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenBuffetEvent extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PREPARED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const COVERS_OCCUPANCY = 'occupancy';
    public const COVERS_MANUAL = 'manual';

    public const COVERS_SOURCES = [
        self::COVERS_OCCUPANCY,
        self::COVERS_MANUAL,
    ];

    protected $fillable = [
        'event_number',
        'event_date',
        'meal_service',
        'name',
        'event_link',
        'branch_id',
        'warehouse_id',
        'covers_source',
        'expected_covers',
        'occupancy_snapshot',
        'price_per_person',
        'status',
        'notes',
        'prepared_by',
        'prepared_at',
        'closed_by',
        'closed_at',
        'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'expected_covers' => 'integer',
        'occupancy_snapshot' => 'array',
        'price_per_person' => 'decimal:2',
        'prepared_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(KitchenBuffetEventLine::class, 'kitchen_buffet_event_id', 'id')->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function losses()
    {
        return $this->hasMany(KitchenLoss::class, 'kitchen_buffet_event_id', 'id')->orderBy('id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by', 'id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by', 'id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPrepared(): bool
    {
        return $this->status === self::STATUS_PREPARED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Reconciliation (plan §9, client rule): prepared = used +
     * leftover + waste, per line and in total. A line that does not
     * reconcile is a bug, not a rounding difference.
     *
     * @return array{prepared: float, used: float, leftover: float, waste: float, balanced: bool, difference: float, expected_revenue: float|null}
     */
    public function summary(): array
    {
        $prepared = 0.0;
        $used = 0.0;
        $leftover = 0.0;
        $waste = 0.0;

        foreach ($this->lines as $line) {
            $prepared += (float) $line->prepared_quantity;
            $used += (float) $line->used_quantity;
            $leftover += (float) $line->leftover_quantity;
            $waste += (float) $line->waste_quantity;
        }

        $prepared = round($prepared, 4);
        $used = round($used, 4);
        $leftover = round($leftover, 4);
        $waste = round($waste, 4);
        $difference = round($prepared - $used - $leftover - $waste, 4);

        return [
            'prepared' => $prepared,
            'used' => $used,
            'leftover' => $leftover,
            'waste' => $waste,
            'balanced' => abs($difference) < 0.0001,
            'difference' => $difference,
            'expected_revenue' => $this->price_per_person !== null
                ? round((float) $this->price_per_person * (int) $this->expected_covers, 2)
                : null,
        ];
    }
}
