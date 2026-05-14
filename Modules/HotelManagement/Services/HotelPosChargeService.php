<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;

class HotelPosChargeService
{
    public function chargeToFolio(HotelFolio $folio, array $payload, int $creatorId): HotelFolioLine
    {
        return $this->addChargeLine($folio, $payload, $creatorId, 'pos_charge');
    }

    /**
     * Manual / outlet charges (restaurant, bar, laundry, damages, etc.) — same totals logic as POS.
     */
    public function addMiscChargeToFolio(HotelFolio $folio, array $payload, int $creatorId): HotelFolioLine
    {
        return $this->addChargeLine($folio, $payload, $creatorId, 'misc_charge');
    }

    private function addChargeLine(HotelFolio $folio, array $payload, int $creatorId, string $lineType): HotelFolioLine
    {
        $qty = max(1, (int) ($payload['quantity'] ?? 1));
        $unitPrice = (float) ($payload['unit_price'] ?? 0);
        $tax = (float) ($payload['tax_amount'] ?? 0);
        $discount = (float) ($payload['discount_amount'] ?? 0);
        $lineTotal = ($qty * $unitPrice) + $tax - $discount;

        $lineData = [
            'hotel_folio_id' => $folio->id,
            'line_type' => $lineType,
            'description' => (string) ($payload['description'] ?? 'Charge'),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_amount' => $tax,
            'discount_amount' => $discount,
            'line_total' => $lineTotal,
            'source_pos_id' => $payload['source_pos_id'] ?? null,
            'created_by' => $creatorId,
        ];
        if (Schema::hasColumn('hotel_folio_lines', 'revenue_channel')) {
            $lineData['revenue_channel'] = isset($payload['revenue_channel']) && $payload['revenue_channel'] !== ''
                ? (string) $payload['revenue_channel']
                : null;
        }
        $line = HotelFolioLine::create($lineData);

        $folio->sub_total = (float) $folio->sub_total + ($qty * $unitPrice);
        $folio->tax_total = (float) $folio->tax_total + $tax;
        $folio->discount_total = (float) $folio->discount_total + $discount;
        $folio->grand_total = (float) $folio->grand_total + $lineTotal;
        $folio->save();

        $this->reopenFolioIfUnderpaid($folio);

        return $line;
    }

    /**
     * New charges after a folio was marked settled must reopen until paid again.
     */
    private function reopenFolioIfUnderpaid(HotelFolio $folio): void
    {
        $folio->refresh();
        $paid = (float) $folio->paid_total;
        $grand = (float) $folio->grand_total;
        if ($grand > 0 && $paid < $grand - 0.01 && $folio->status === 'settled') {
            $folio->update(['status' => 'open']);
        }
    }
}
