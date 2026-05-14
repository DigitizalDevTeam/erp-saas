<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelAccountingSetting extends Model
{
    use HasFactory;

    protected $table = 'hotel_accounting_settings';

    protected $fillable = [
        'created_by',
        'chart_guest_ar_id',
        'chart_room_revenue_id',
        'chart_restaurant_revenue_id',
        'chart_laundry_revenue_id',
        'chart_misc_revenue_id',
        'chart_vat_payable_id',
        'default_vat_rate_percent',
        'pos_invoice_when_charge_to_room',
        'folio_payment_bank_map',
    ];

    protected $casts = [
        'folio_payment_bank_map' => 'array',
        'pos_invoice_when_charge_to_room' => 'boolean',
        'default_vat_rate_percent' => 'decimal:2',
    ];

    public function isCompleteForPosting(): bool
    {
        return (bool) $this->chart_guest_ar_id
            && (bool) $this->chart_room_revenue_id
            && (bool) $this->chart_restaurant_revenue_id
            && (bool) $this->chart_laundry_revenue_id
            && (bool) $this->chart_misc_revenue_id
            && (bool) $this->chart_vat_payable_id;
    }

    public function revenueAccountId(string $channel): ?int
    {
        return match ($channel) {
            'room' => $this->chart_room_revenue_id ? (int) $this->chart_room_revenue_id : null,
            'restaurant' => $this->chart_restaurant_revenue_id ? (int) $this->chart_restaurant_revenue_id : null,
            'laundry' => $this->chart_laundry_revenue_id ? (int) $this->chart_laundry_revenue_id : null,
            'misc' => $this->chart_misc_revenue_id ? (int) $this->chart_misc_revenue_id : null,
            default => null,
        };
    }

    public function bankAccountIdForFolioPaymentMethod(string $method): ?int
    {
        $map = $this->folio_payment_bank_map ?? [];
        if (isset($map[$method]) && (int) $map[$method] > 0) {
            return (int) $map[$method];
        }

        return null;
    }
}
