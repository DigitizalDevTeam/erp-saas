<?php

namespace Modules\HotelManagement\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;

class LowOccupancyAlertCommand extends Command
{
    protected $signature = 'hotelmanagement:low-occupancy-alert {--threshold=40}';

    protected $description = 'Log a low-occupancy alert when forecast occupancy is below threshold.';

    public function handle(): int
    {
        if (!Schema::hasTable('hotel_rooms')) {
            return self::SUCCESS;
        }
        $threshold = (float) $this->option('threshold');
        $tenants = HotelRoom::select('created_by')->groupBy('created_by')->pluck('created_by');
        foreach ($tenants as $tenantId) {
            $total = max(1, HotelRoom::where('created_by', $tenantId)->count());
            $today = now()->toDateString();
            $tomorrow = now()->addDay()->toDateString();
            $inHouse = HotelReservation::where('created_by', $tenantId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $tomorrow)
                ->whereDate('check_out_date', '>', $today)
                ->count();
            $pct = round(($inHouse / $total) * 100, 1);
            if ($pct < $threshold) {
                Log::notice("[hotel:low-occupancy] tenant=$tenantId forecast={$pct}% < {$threshold}%");
                $this->warn("Tenant $tenantId low occupancy: {$pct}%");
            }
        }

        return self::SUCCESS;
    }
}
