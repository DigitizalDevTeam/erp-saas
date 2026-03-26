<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StockAlertsMail extends Mailable
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
        $subject = __('Stock Alerts');
        $fromAddress = $this->settings['mail_from_address'] ?? null;
        $fromName = $this->settings['company_name'] ?? ($this->settings['mail_from_name'] ?? null);

        $m = $this->subject($subject)->markdown('email.stock_alerts');
        if ($fromAddress) {
            $m = $m->from($fromAddress, $fromName ?: null);
        }
        return $m;
    }
}

