<?php

namespace Modules\HotelManagement\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelGuest;

class SendBirthdayPromotionsCommand extends Command
{
    protected $signature = 'hotelmanagement:send-birthday-promotions';

    protected $description = 'Notify guests whose birthday is today (logs / WhatsApp via existing Twilio when available).';

    public function handle(): int
    {
        if (!Schema::hasTable('hotel_guests')) {
            $this->info('hotel_guests table not present — skipping.');

            return self::SUCCESS;
        }

        $today = now();
        $guests = HotelGuest::whereNotNull('birth_date')
            ->whereMonth('birth_date', $today->month)
            ->whereDay('birth_date', $today->day)
            ->get();

        $count = 0;
        foreach ($guests as $g) {
            $message = sprintf('Happy birthday %s! Enjoy a special offer at our hotel.', trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? '')));
            Log::info('[hotel:birthday] ' . $message . ' phone=' . ($g->phone ?? '-'));
            if (!empty($g->phone) && class_exists(\App\Models\Utility::class) && method_exists(\App\Models\Utility::class, 'send_twilio_msg')) {
                try {
                    \App\Models\Utility::send_twilio_msg(
                        $g->phone,
                        'hotel_birthday_promotion',
                        ['guest_name' => trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? ''))],
                        $g->created_by
                    );
                } catch (\Throwable $e) {
                    Log::warning('[hotel:birthday] twilio failed: ' . $e->getMessage());
                }
            }
            $count++;
        }

        $this->info('Birthday notifications processed: ' . $count);

        return self::SUCCESS;
    }
}
