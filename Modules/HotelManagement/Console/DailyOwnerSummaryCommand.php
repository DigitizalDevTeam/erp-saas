<?php

namespace Modules\HotelManagement\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;

class DailyOwnerSummaryCommand extends Command
{
    protected $signature = 'hotelmanagement:daily-owner-summary';

    protected $description = 'Stub: log a daily owner summary (occupancy / arrivals / revenue).';

    public function handle(): int
    {
        if (!Schema::hasTable('hotel_rooms')) {
            $this->info('hotel_rooms table not present — skipping.');

            return self::SUCCESS;
        }
        $today = now()->toDateString();
        $rooms = HotelRoom::query()->selectRaw('created_by, COUNT(*) as total, SUM(CASE WHEN status = "occupied" THEN 1 ELSE 0 END) as occ')
            ->groupBy('created_by')
            ->get();

        foreach ($rooms as $r) {
            $arrivals = HotelReservation::where('created_by', $r->created_by)->whereDate('check_in_date', $today)->count();
            $departures = HotelReservation::where('created_by', $r->created_by)->whereDate('check_out_date', $today)->count();
            $revenue = (float) HotelFolio::where('created_by', $r->created_by)->whereDate('created_at', $today)->sum('grand_total');
            $occPct = $r->total > 0 ? round(($r->occ / $r->total) * 100, 1) : 0;
            $msg = sprintf(
                '[hotel:daily-summary] tenant=%d occ=%s%% arrivals=%d departures=%d revenue=%s',
                $r->created_by, $occPct, $arrivals, $departures, number_format($revenue, 2)
            );
            Log::info($msg);
            $this->info($msg);
        }

        return self::SUCCESS;
    }
}
