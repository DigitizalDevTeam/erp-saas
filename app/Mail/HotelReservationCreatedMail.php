<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\HotelManagement\Entities\HotelReservation;

class HotelReservationCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public HotelReservation $reservation,
        public array $payload
    ) {
    }

    public function build()
    {
        return $this->subject(__('Booking Confirmation') . ' - ' . ($this->reservation->reservation_no ?? ''))
            ->view('emails.hotel_reservation_created');
    }
}
