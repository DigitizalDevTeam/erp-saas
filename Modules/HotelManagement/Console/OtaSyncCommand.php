<?php

namespace Modules\HotelManagement\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelOtaSetting;

class OtaSyncCommand extends Command
{
    protected $signature = 'hotelmanagement:ota-sync';

    protected $description = 'Stub: sync reservations with enabled OTA channels (Booking.com/Agoda/iCal).';

    public function handle(): int
    {
        if (!Schema::hasTable('hotel_ota_settings')) {
            $this->info('hotel_ota_settings table not present — skipping.');

            return self::SUCCESS;
        }
        $active = HotelOtaSetting::where('enabled', true)->get();
        if ($active->isEmpty()) {
            $this->info('No enabled OTA channels.');

            return self::SUCCESS;
        }
        foreach ($active as $s) {
            Log::info('[hotel:ota-sync] ' . $s->channel . ' for tenant=' . $s->created_by . ' (stub call only)');
            $this->info('OTA stub sync: ' . $s->channel);
        }

        return self::SUCCESS;
    }
}
