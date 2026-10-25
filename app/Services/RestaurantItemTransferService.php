<?php

namespace App\Services;

use App\Models\Pos;
use App\Models\PosProduct;
use InvalidArgumentException;

/**
 * Transfer items between two open restaurant tables.
 *
 * Only saved order lines move: the ticked lines are re-pointed from the
 * source check to the destination check (same mechanism as Join/Move Table).
 * Stock is untouched — it was already deducted when the items were sent to
 * production — and each line keeps its own status, price, note and options,
 * so lines are merged at order level, never summed into one row.
 */
class RestaurantItemTransferService
{
    /**
     * @param  int[]  $lineIds
     * @param  array  $sourceSessionRows  Unsent session-cart rows still sitting on the source table.
     * @return array{moved_count: int, source_emptied: bool}
     *
     * @throws InvalidArgumentException
     */
    public function transferLines(Pos $sourcePos, Pos $targetPos, array $lineIds, array $sourceSessionRows = []): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            throw new InvalidArgumentException('No items selected.');
        }
        if ((int) $sourcePos->id === (int) $targetPos->id) {
            throw new InvalidArgumentException('Source and destination orders must differ.');
        }

        $lines = PosProduct::whereIn('id', $lineIds)->get();
        if ($lines->count() !== count($lineIds)) {
            throw new InvalidArgumentException('Some items no longer exist.');
        }
        foreach ($lines as $line) {
            if ((int) $line->pos_id !== (int) $sourcePos->id) {
                throw new InvalidArgumentException('Some items do not belong to the source table.');
            }
            if (! empty($line->voided_at) || ! empty($line->removed_from_cart_at) || $line->status === PosProduct::STATUS_CANCELLED) {
                throw new InvalidArgumentException('Some items can no longer be transferred.');
            }
        }

        foreach ($lines as $line) {
            $line->pos_id = $targetPos->id;
            $line->save();
        }

        $remaining = PosProduct::where('pos_id', $sourcePos->id)
            ->whereNull('voided_at')
            ->whereNull('removed_from_cart_at')
            ->where('status', '!=', PosProduct::STATUS_CANCELLED)
            ->count();

        // The source table is only free when no saved line is left AND no
        // unsent draft is still sitting in the session cart for it.
        return [
            'moved_count' => count($lineIds),
            'source_emptied' => $remaining === 0 && empty($sourceSessionRows),
        ];
    }
}
