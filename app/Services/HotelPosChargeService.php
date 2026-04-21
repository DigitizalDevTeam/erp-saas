<?php

namespace App\Services;

use App\Models\HotelFolio;
use App\Models\HotelFolioLine;

class HotelPosChargeService
{
    public function chargeToFolio(HotelFolio $folio, array $payload, int $creatorId): HotelFolioLine
    {
        $qty = max(1, (int) ($payload['quantity'] ?? 1));
        $unitPrice = (float) ($payload['unit_price'] ?? 0);
        $tax = (float) ($payload['tax_amount'] ?? 0);
        $discount = (float) ($payload['discount_amount'] ?? 0);
        $lineTotal = ($qty * $unitPrice) + $tax - $discount;

        $line = HotelFolioLine::create([
            'hotel_folio_id' => $folio->id,
            'line_type' => 'pos_charge',
            'description' => (string) ($payload['description'] ?? 'POS Charge'),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_amount' => $tax,
            'discount_amount' => $discount,
            'line_total' => $lineTotal,
            'source_pos_id' => $payload['source_pos_id'] ?? null,
            'created_by' => $creatorId,
        ]);

        $folio->sub_total = (float) $folio->sub_total + ($qty * $unitPrice);
        $folio->tax_total = (float) $folio->tax_total + $tax;
        $folio->discount_total = (float) $folio->discount_total + $discount;
        $folio->grand_total = (float) $folio->grand_total + $lineTotal;
        $folio->save();

        return $line;
    }
}
