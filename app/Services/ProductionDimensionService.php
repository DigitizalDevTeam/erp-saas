<?php

namespace App\Services;

use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\KitchenProductionCostDimension;
use App\Models\KitchenProductionOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre Phase 7 — production.
 *
 * When a kitchen production order completes, the consumed-ingredient
 * cost (the order's actual_cost) is attributed to the order's cost
 * centre in its own table. Attribution beside the money only: stock
 * deduction and journals are untouched, and one order attributes
 * exactly once.
 */
class ProductionDimensionService
{
    /**
     * Attribute a completed order's ingredient cost to its centre.
     * Returns the attribution row, or null when there is nothing to
     * attribute (no centre, zero cost, table missing, or a centre
     * from another company). Idempotent: a second call returns the
     * existing row.
     */
    public function attributeCompletion(KitchenProductionOrder $order, int $userId): ?KitchenProductionCostDimension
    {
        if (! $this->tableExists() || $order->cost_centre_id === null) {
            return null;
        }

        $amount = number_format((float) ($order->actual_cost ?? 0), 2, '.', '');
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        $creatorId = (int) $order->created_by;
        $centre = CostCentre::find((int) $order->cost_centre_id);
        if (! $centre || (int) $centre->created_by !== $creatorId) {
            return null;
        }

        $existing = KitchenProductionCostDimension::where('kitchen_production_order_id', (int) $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $productionDate = $order->production_date;
        if ($productionDate === null && $order->completed_at !== null) {
            $productionDate = Carbon::parse($order->completed_at)->toDateString();
        }
        if ($productionDate === null) {
            $productionDate = Carbon::today()->toDateString();
        }

        $row = KitchenProductionCostDimension::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'kitchen_production_order_id' => (int) $order->id,
            'cost_centre_id' => (int) $order->cost_centre_id,
            'project_id' => null,
            'amount' => $amount,
            'currency_code' => $this->baseCurrency($creatorId),
            'status' => 'active',
            'production_date' => $productionDate,
        ]);

        DimensionAuditEvent::record(
            $creatorId,
            'kitchen_production_cost_dimension',
            (int) $row->id,
            'attributed',
            null,
            $row->only(['kitchen_production_order_id', 'cost_centre_id', 'amount', 'production_date']),
            $userId
        );

        return $row;
    }

    /**
     * Production cost totals grouped by (centre, project) for a
     * period. Returns rows of
     * [cost_centre_id, project_id, production_total].
     */
    public function productionCostsByCentre(int $creatorId, Carbon $from, Carbon $to, ?int $centreId = null, ?int $projectId = null): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $query = KitchenProductionCostDimension::forCompany($creatorId)
            ->where('status', 'active')
            ->whereBetween('production_date', [$from->toDateString(), $to->toDateString()]);

        if ($centreId !== null) {
            $query->where('cost_centre_id', $centreId);
        }
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query
            ->select('cost_centre_id', 'project_id', DB::raw('SUM(amount) as production_total'))
            ->groupBy('cost_centre_id', 'project_id')
            ->get()
            ->map(fn ($row) => [
                'cost_centre_id' => (int) $row->cost_centre_id,
                'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                'production_total' => number_format((float) $row->production_total, 2, '.', ''),
            ])
            ->all();
    }

    public function tableExists(): bool
    {
        // Live check on every call, never memoized.
        return Schema::hasTable('kitchen_production_cost_dimensions');
    }

    protected function baseCurrency(int $creatorId): ?string
    {
        try {
            return (new BudgetControlService())->baseCurrency($creatorId);
        } catch (\Throwable) {
            return null;
        }
    }
}
