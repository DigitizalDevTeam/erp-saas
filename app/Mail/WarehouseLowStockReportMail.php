<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Shift-end low-stock report, one row per product + warehouse.
 * Same shape as StockAlertsMail, with a Warehouse column added.
 * Min only: the shift report's minimum-stock rows are, by definition,
 * the items at or below their Low level.
 */
class WarehouseLowStockReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $data;
    public array $settings;

    public function __construct(array $data, array $settings)
    {
        $this->data = $data;
        $this->settings = $settings;
    }

    public function build()
    {
        $subject = __('Low Stock Report by Warehouse');
        $fromAddress = $this->settings['mail_from_address'] ?? null;
        $fromName = $this->settings['company_name'] ?? ($this->settings['mail_from_name'] ?? null);

        $m = $this->subject($subject)->markdown('email.warehouse_stock_alerts');
        if ($fromAddress) {
            $m = $m->from($fromAddress, $fromName ?: null);
        }

        return $m;
    }
}
