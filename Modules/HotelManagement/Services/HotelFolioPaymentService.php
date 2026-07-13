<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioPayment;

class HotelFolioPaymentService
{
    /**
     * UI / front-desk payment method keys mapped to stored folio payment methods.
     *
     * @return array<string, string>
     */
    public static function paymentMethodOptions(): array
    {
        return [
            'cash' => __('Cash'),
            'mpesa' => __('M-Pesa'),
            'card' => __('Card'),
            'bank_transfer' => __('Bank Transfer'),
            'e_mola' => __('e-Mola'),
            'mkesh' => __('Mkesh'),
        ];
    }

    public static function normalizeMethod(string $method): string
    {
        return match ($method) {
            'transfer', 'account' => 'bank_transfer',
            'other' => 'cash',
            default => $method,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function allowedMethods(): array
    {
        return array_keys(self::paymentMethodOptions());
    }

    /**
     * Record a folio payment and refresh folio totals.
     */
    public function recordPayment(
        HotelFolio $folio,
        float $amount,
        string $method,
        int $creatorId,
        ?string $reference = null,
        ?string $notes = null,
    ): HotelFolioPayment {
        if (! Schema::hasTable('hotel_folio_payments')) {
            throw new \RuntimeException(__('Run migrations to enable folio payments.'));
        }

        $method = self::normalizeMethod($method);
        if (! in_array($method, self::allowedMethods(), true)) {
            throw new \InvalidArgumentException(__('Invalid payment method.'));
        }

        if ($amount < 0.01) {
            throw new \InvalidArgumentException(__('Payment amount must be greater than zero.'));
        }

        $payment = null;

        DB::transaction(function () use ($folio, $amount, $method, $creatorId, $reference, $notes, &$payment) {
            $payload = [
                'hotel_folio_id' => $folio->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $creatorId,
            ];
            if (Schema::hasColumn('hotel_folio_payments', 'recorded_by')) {
                $payload['recorded_by'] = Auth::id();
            }
            $payment = HotelFolioPayment::create($payload);

            $this->refreshFolioPaidTotal($folio);
        });

        app(HotelFolioAccountingService::class)->postFolioPaymentJournalIfConfigured($payment);

        return $payment;
    }

    public function refreshFolioPaidTotal(HotelFolio $folio): void
    {
        $folio->refresh();
        $paid = (float) $folio->payments()->sum('amount');
        $updates = ['paid_total' => $paid];

        if ($paid >= (float) $folio->grand_total - 0.01 && $folio->status === 'open') {
            $updates['status'] = 'settled';
        }

        $folio->update($updates);
    }

    public function pendingBalance(HotelFolio $folio): float
    {
        $folio->refresh();

        return max(0, (float) $folio->grand_total - (float) $folio->paid_total);
    }
}
