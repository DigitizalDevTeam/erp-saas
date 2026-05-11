<?php

namespace Tests\Unit\HotelManagement;

use App\Mail\HotelReservationCreatedMail;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Tests\TestCase;

class HotelReservationCreatedMailTest extends TestCase
{
    public function test_booking_confirmation_mail_renders_reservation_details(): void
    {
        $guest = new HotelGuest([
            'first_name' => 'John',
            'last_name' => 'Guest',
        ]);
        $roomType = new HotelRoomType(['name' => 'Executive Apartment']);
        $room = new HotelRoom(['room_no' => 'A-201']);
        $reservation = new HotelReservation([
            'reservation_no' => 'RSV-20260511-TEST1',
            'check_in_at' => '2026-05-12 14:00:00',
            'check_out_at' => '2026-06-12 12:00:00',
            'estimated_arrival_time' => '18:30:00',
            'rate_per_night' => 2500,
            'billing_mode' => 'monthly',
        ]);
        $reservation->setRelation('guest', $guest);
        $reservation->setRelation('roomType', $roomType);
        $reservation->setRelation('room', $room);

        $mail = new HotelReservationCreatedMail($reservation, [
            'billing_mode' => 'monthly',
            'rate_display' => '2,500.00 / month',
        ]);

        $rendered = $mail->render();

        $this->assertStringContainsString('RSV-20260511-TEST1', $rendered);
        $this->assertStringContainsString('John Guest', $rendered);
        $this->assertStringContainsString('Executive Apartment', $rendered);
        $this->assertStringContainsString('2,500.00 / month', $rendered);
    }
}
