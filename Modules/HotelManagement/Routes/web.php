<?php

/*
|--------------------------------------------------------------------------
| HotelManagement Module Web Routes
|--------------------------------------------------------------------------
*/

use Modules\HotelManagement\Http\Controllers\HotelCalendarController;
use Modules\HotelManagement\Http\Controllers\HotelDashboardController;
use Modules\HotelManagement\Http\Controllers\HotelFolioController;
use Modules\HotelManagement\Http\Controllers\HotelFrontdeskController;
use Modules\HotelManagement\Http\Controllers\HotelGroupController;
use Modules\HotelManagement\Http\Controllers\HotelHousekeepingController;
use Modules\HotelManagement\Http\Controllers\HotelNightAuditController;
use Modules\HotelManagement\Http\Controllers\HotelOtaSettingsController;
use Modules\HotelManagement\Http\Controllers\HotelPosIntegrationController;
use Modules\HotelManagement\Http\Controllers\HotelReportController;
use Modules\HotelManagement\Http\Controllers\HotelReservationController;
use Modules\HotelManagement\Http\Controllers\HotelRoomController;
use Modules\HotelManagement\Http\Controllers\HotelRoomTypeController;

Route::group(['middleware' => ['verified']], function () {
    Route::get('/hotel-dashboard', [HotelDashboardController::class, 'index'])
        ->name('hotel.dashboard')
        ->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('hotel/calendar', [HotelCalendarController::class, 'index'])->name('hotel.calendar');
            Route::get('hotel/calendar/events', [HotelCalendarController::class, 'events'])->name('hotel.calendar.events');

            Route::get('hotel/reports/operational', [HotelReportController::class, 'operational'])->name('hotel.reports.operational');
            Route::get('hotel/reports/operational/pdf', [HotelReportController::class, 'operationalPdf'])->name('hotel.reports.operational.pdf');
            Route::get('hotel/reports/operational/export', [HotelReportController::class, 'operationalExport'])->name('hotel.reports.operational.export');
            Route::get('hotel/reports/financial', [HotelReportController::class, 'financial'])->name('hotel.reports.financial');
            Route::get('hotel/reports/financial/export', [HotelReportController::class, 'financialExport'])->name('hotel.reports.financial.export');
            Route::get('hotel/reports/occupancy', [HotelReportController::class, 'occupancy'])->name('hotel.reports.occupancy');
            Route::get('hotel/reports/customers', [HotelReportController::class, 'customers'])->name('hotel.reports.customers');
            Route::get('hotel/reports/alerts', [HotelReportController::class, 'alerts'])->name('hotel.reports.alerts');

            Route::get('hotel/ota-settings', [HotelOtaSettingsController::class, 'index'])->name('hotel.ota-settings.index');
            Route::post('hotel/ota-settings', [HotelOtaSettingsController::class, 'update'])->name('hotel.ota-settings.update');

            Route::resource('hotel/groups', HotelGroupController::class)->only(['index', 'create', 'store', 'show'])->names('hotel.groups');

            Route::get('hotel/reservations/guest-search', [HotelReservationController::class, 'guestSearch'])->name('hotel.reservations.guest-search');
            Route::get('hotel/reservations/rate-quote', [HotelReservationController::class, 'rateQuote'])->name('hotel.reservations.rate-quote');
            Route::get('hotel/reservations/suggest-room', [HotelReservationController::class, 'suggestRoom'])->name('hotel.reservations.suggest-room');
            Route::post('hotel/reservations/store-and-check-in', [HotelReservationController::class, 'storeAndCheckIn'])->name('hotel.reservations.store-and-check-in');
            Route::post('hotel/reservations/{reservation}/calendar-update', [HotelReservationController::class, 'calendarUpdate'])->name('hotel.reservations.calendar-update');

            Route::post('hotel/folios/{folio}/payments', [HotelFolioController::class, 'storePayment'])->name('hotel.folios.payments.store');

            Route::get('hotel/rooms/map', [HotelRoomController::class, 'map'])->name('hotel.rooms.map');
            Route::resource('hotel/rooms', HotelRoomController::class)->names('hotel.rooms');
            Route::resource('hotel/room-types', HotelRoomTypeController::class)->except(['show'])->names('hotel.room-types');
            Route::resource('hotel/reservations', HotelReservationController::class)->names('hotel.reservations');
            Route::resource('hotel/housekeeping', HotelHousekeepingController::class)->except(['show', 'destroy'])->names('hotel.housekeeping');
            Route::get('hotel/frontdesk/in-house', [HotelFrontdeskController::class, 'inHouse'])->name('hotel.frontdesk.in-house');
            Route::post('hotel/frontdesk/check-in/{reservation}', [HotelFrontdeskController::class, 'checkIn'])->name('hotel.frontdesk.check-in');
            Route::post('hotel/frontdesk/check-out/{stay}', [HotelFrontdeskController::class, 'checkOut'])->name('hotel.frontdesk.check-out');
            Route::get('hotel/folios/{folio}', [HotelFolioController::class, 'show'])->name('hotel.folios.show');
            Route::get('hotel/night-audit', [HotelNightAuditController::class, 'index'])->name('hotel.night-audit.index');
            Route::post('hotel/night-audit/run', [HotelNightAuditController::class, 'run'])->name('hotel.night-audit.run');
            Route::get('hotel/pos/in-house-guests', [HotelPosIntegrationController::class, 'inHouseGuests'])->name('hotel.pos.in-house-guests');
            Route::post('hotel/pos/charge', [HotelPosIntegrationController::class, 'charge'])->name('hotel.pos.charge');
        }
    );
});
