<?php

/*
|--------------------------------------------------------------------------
| HotelManagement Module Web Routes
|--------------------------------------------------------------------------
*/

use Modules\HotelManagement\Http\Controllers\HotelBookingSiteController;
use Modules\HotelManagement\Http\Controllers\HotelPriceConfiguratorController;
use Modules\HotelManagement\Http\Controllers\HotelPriceRuleController;
use Modules\HotelManagement\Http\Controllers\HotelAddonController;
use Modules\HotelManagement\Http\Controllers\HotelAccountingSettingsController;
use Modules\HotelManagement\Http\Controllers\HotelCalendarController;
use Modules\HotelManagement\Http\Controllers\HotelCashierShiftController;
use Modules\HotelManagement\Http\Controllers\HotelDashboardController;
use Modules\HotelManagement\Http\Controllers\HotelExpenseController;
use Modules\HotelManagement\Http\Controllers\HotelFinancialReportController;
use Modules\HotelManagement\Http\Controllers\HotelOperationReportController;
use Modules\HotelManagement\Http\Controllers\HotelFolioController;
use Modules\HotelManagement\Http\Controllers\HotelFloorController;
use Modules\HotelManagement\Http\Controllers\HotelFrontdeskController;
use Modules\HotelManagement\Http\Controllers\HotelGroupController;
use Modules\HotelManagement\Http\Controllers\HotelGuestController;
use Modules\HotelManagement\Http\Controllers\HotelGuestMapController;
use Modules\HotelManagement\Http\Controllers\HotelHousekeepingController;
use Modules\HotelManagement\Http\Controllers\HotelMinibarItemController;
use Modules\HotelManagement\Http\Controllers\HotelNightAuditController;
use Modules\HotelManagement\Http\Controllers\HotelOtaSettingsController;
use Modules\HotelManagement\Http\Controllers\HotelPosIntegrationController;
use Modules\HotelManagement\Http\Controllers\HotelRatePlanController;
use Modules\HotelManagement\Http\Controllers\HotelReportController;
use Modules\HotelManagement\Http\Controllers\HotelReservationController;
use Modules\HotelManagement\Http\Controllers\HotelRoomConsumptionController;
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
            Route::get('hotel/booking-site', [HotelBookingSiteController::class, 'edit'])->name('hotel.booking-site.edit');
            Route::post('hotel/booking-site', [HotelBookingSiteController::class, 'update'])->name('hotel.booking-site.update');

            Route::get('hotel/accounting-settings', [HotelAccountingSettingsController::class, 'index'])->name('hotel.accounting-settings.index');
            Route::post('hotel/accounting-settings', [HotelAccountingSettingsController::class, 'update'])->name('hotel.accounting-settings.update');

            Route::get('hotel/calendar', [HotelCalendarController::class, 'index'])->name('hotel.calendar');
            Route::get('hotel/calendar/events', [HotelCalendarController::class, 'events'])->name('hotel.calendar.events');
            Route::get('hotel/calendar/room-board', [HotelCalendarController::class, 'roomBoard'])->name('hotel.calendar.room-board');
            Route::post('hotel/calendar/reservations/{reservation}/extend-stay', [HotelCalendarController::class, 'extendStay'])->name('hotel.calendar.extend-stay');
            Route::post('hotel/calendar/reservations/{reservation}/change-room', [HotelCalendarController::class, 'changeRoom'])->name('hotel.calendar.change-room');
            Route::post('hotel/calendar/rooms/{room}/status', [HotelCalendarController::class, 'changeRoomStatus'])->name('hotel.calendar.room-status');

            Route::get('hotel/reports', [HotelReportController::class, 'index'])->name('hotel.reports.index');
            Route::get('hotel/reports/operational', [HotelReportController::class, 'operational'])->name('hotel.reports.operational');
            Route::get('hotel/reports/operational/pdf', [HotelReportController::class, 'operationalPdf'])->name('hotel.reports.operational.pdf');
            Route::get('hotel/reports/operational/export', [HotelReportController::class, 'operationalExport'])->name('hotel.reports.operational.export');
            Route::get('hotel/reports/financial', [HotelReportController::class, 'financial'])->name('hotel.reports.financial');
            Route::get('hotel/reports/financial/pdf', [HotelReportController::class, 'financialPdf'])->name('hotel.reports.financial.pdf');
            Route::get('hotel/reports/financial/export', [HotelReportController::class, 'financialExport'])->name('hotel.reports.financial.export');
            Route::get('hotel/reports/immigration', [HotelReportController::class, 'immigration'])->name('hotel.reports.immigration');
            Route::get('hotel/reports/immigration/pdf', [HotelReportController::class, 'immigrationPdf'])->name('hotel.reports.immigration.pdf');
            Route::get('hotel/reports/immigration/export', [HotelReportController::class, 'immigrationExport'])->name('hotel.reports.immigration.export');
            Route::get('hotel/reports/occupancy', [HotelReportController::class, 'occupancy'])->name('hotel.reports.occupancy');
            Route::get('hotel/reports/customers', [HotelReportController::class, 'customers'])->name('hotel.reports.customers');
            Route::get('hotel/reports/alerts', [HotelReportController::class, 'alerts'])->name('hotel.reports.alerts');

            // Financial reports (each supports ?export=pdf|excel)
            Route::get('hotel/reports/financial/daily-revenue', [HotelFinancialReportController::class, 'dailyRevenue'])->name('hotel.reports.financial.daily-revenue');
            Route::get('hotel/reports/financial/monthly-revenue', [HotelFinancialReportController::class, 'monthlyRevenue'])->name('hotel.reports.financial.monthly-revenue');
            Route::get('hotel/reports/financial/payment-collection', [HotelFinancialReportController::class, 'paymentCollection'])->name('hotel.reports.financial.payment-collection');
            Route::get('hotel/reports/financial/outstanding', [HotelFinancialReportController::class, 'outstanding'])->name('hotel.reports.financial.outstanding');
            Route::get('hotel/reports/financial/cashier-closing', [HotelFinancialReportController::class, 'cashierClosing'])->name('hotel.reports.financial.cashier-closing');
            Route::get('hotel/reports/financial/occupancy-revenue', [HotelFinancialReportController::class, 'occupancyRevenue'])->name('hotel.reports.financial.occupancy-revenue');

            // Operation reports (each supports ?export=pdf|excel)
            Route::get('hotel/reports/operations/reservations', [HotelOperationReportController::class, 'reservations'])->name('hotel.reports.ops.reservations');
            Route::get('hotel/reports/operations/check-in', [HotelOperationReportController::class, 'checkIn'])->name('hotel.reports.ops.check-in');
            Route::get('hotel/reports/operations/check-out', [HotelOperationReportController::class, 'checkOut'])->name('hotel.reports.ops.check-out');
            Route::get('hotel/reports/operations/room-status', [HotelOperationReportController::class, 'roomStatus'])->name('hotel.reports.ops.room-status');
            Route::get('hotel/reports/operations/housekeeping', [HotelOperationReportController::class, 'housekeeping'])->name('hotel.reports.ops.housekeeping');

            Route::get('hotel/ota-settings', [HotelOtaSettingsController::class, 'index'])->name('hotel.ota-settings.index');
            Route::post('hotel/ota-settings', [HotelOtaSettingsController::class, 'update'])->name('hotel.ota-settings.update');

            Route::get('hotel/price-configurator', [HotelPriceConfiguratorController::class, 'index'])->name('hotel.price-configurator.index');
            Route::post('hotel/price-configurator/settings', [HotelPriceConfiguratorController::class, 'updateSettings'])->name('hotel.price-configurator.settings');
            Route::post('hotel/price-configurator/calculate', [HotelPriceConfiguratorController::class, 'calculate'])->name('hotel.price-configurator.calculate');
            Route::resource('hotel/price-configurator/rules', HotelPriceRuleController::class)->names('hotel.price-rules')->except(['index', 'show']);

            Route::resource('hotel/groups', HotelGroupController::class)->names('hotel.groups');

            Route::get('hotel/reservations/guest-search', [HotelReservationController::class, 'guestSearch'])->name('hotel.reservations.guest-search');
            Route::get('hotel/reservations/rate-quote', [HotelReservationController::class, 'rateQuote'])->name('hotel.reservations.rate-quote');
            Route::get('hotel/reservations/suggest-room', [HotelReservationController::class, 'suggestRoom'])->name('hotel.reservations.suggest-room');
            Route::post('hotel/reservations/store-and-check-in', [HotelReservationController::class, 'storeAndCheckIn'])->name('hotel.reservations.store-and-check-in');
            Route::post('hotel/reservations/{reservation}/calendar-update', [HotelReservationController::class, 'calendarUpdate'])->name('hotel.reservations.calendar-update');

            Route::post('hotel/folios/{folio}/payments', [HotelFolioController::class, 'storePayment'])->name('hotel.folios.payments.store');
            Route::get('hotel/folios/{folio}/payments/{payment}/receipt', [HotelFolioController::class, 'paymentReceiptPrint'])->name('hotel.folios.payments.receipt');
            Route::post('hotel/folios/{folio}/lines', [HotelFolioController::class, 'storeLine'])->name('hotel.folios.lines.store');

            Route::get('hotel/rooms/map', [HotelRoomController::class, 'map'])->name('hotel.rooms.map');
            Route::post('hotel/rooms/bulk-status', [HotelRoomController::class, 'bulkStatus'])->name('hotel.rooms.bulk-status');
            Route::post('hotel/rooms/{room}/mark-ready', [HotelRoomController::class, 'markReady'])->name('hotel.rooms.mark-ready');
            Route::post('hotel/rooms/{room}/block', [HotelRoomController::class, 'block'])->name('hotel.rooms.block');
            Route::post('hotel/rooms/{room}/unblock', [HotelRoomController::class, 'unblock'])->name('hotel.rooms.unblock');
            Route::get('hotel/consumption/product-info', [HotelRoomConsumptionController::class, 'productInfo'])->name('hotel.consumption.product-info');
            Route::get('hotel/rooms/{room}/consumption', [HotelRoomConsumptionController::class, 'create'])->name('hotel.rooms.consumption');
            Route::post('hotel/rooms/{room}/consumption', [HotelRoomConsumptionController::class, 'store'])->name('hotel.rooms.consumption.store');
            Route::get('hotel/housekeeping/minibar', [HotelHousekeepingController::class, 'minibar'])->name('hotel.housekeeping.minibar');
            Route::post('hotel/housekeeping/minibar', [HotelHousekeepingController::class, 'storeMinibar'])->name('hotel.housekeeping.minibar.store');
            Route::post('hotel/housekeeping/minibar-restock/{restock}/complete', [HotelHousekeepingController::class, 'completeRestock'])->name('hotel.housekeeping.restock.complete');
            Route::post('hotel/minibar-items/seed-defaults', [HotelMinibarItemController::class, 'seedDefaults'])->name('hotel.minibar-items.seed-defaults');
            Route::resource('hotel/minibar-items', HotelMinibarItemController::class)->except(['show'])->names('hotel.minibar-items');
            Route::get('hotel/guests/map', [HotelGuestMapController::class, 'index'])->name('hotel.guests.map');
            Route::post('hotel/guests/{guest}/sync-crm', [HotelGuestController::class, 'syncCrm'])->name('hotel.guests.sync-crm');
            Route::resource('hotel/guests', HotelGuestController::class)->only(['index', 'show', 'edit', 'update'])->names('hotel.guests');
            Route::resource('hotel/addons', HotelAddonController::class)->except(['show'])->names('hotel.addons');
            Route::resource('hotel/floors', HotelFloorController::class)->except(['show'])->names('hotel.floors');
            Route::resource('hotel/rooms', HotelRoomController::class)->names('hotel.rooms');
            Route::resource('hotel/room-types', HotelRoomTypeController::class)->except(['show'])->names('hotel.room-types');
            Route::resource('hotel/rate-plans', HotelRatePlanController::class)->except(['show'])->names('hotel.rate-plans');
            Route::resource('hotel/reservations', HotelReservationController::class)->names('hotel.reservations');
            Route::post('hotel/housekeeping/{housekeeping}/action', [HotelHousekeepingController::class, 'executeAction'])->name('hotel.housekeeping.action');
            Route::resource('hotel/housekeeping', HotelHousekeepingController::class)->except(['show', 'destroy'])->names('hotel.housekeeping');
            Route::get('hotel/frontdesk/in-house', [HotelFrontdeskController::class, 'inHouse'])->name('hotel.frontdesk.in-house');
            Route::get('hotel/frontdesk/checkout/{reservation}', [HotelFrontdeskController::class, 'showCheckout'])->name('hotel.frontdesk.checkout');
            Route::get('hotel/frontdesk/receipt/{reservation}', [HotelFrontdeskController::class, 'showReceipt'])->name('hotel.frontdesk.receipt');
            Route::post('hotel/frontdesk/receipt/{reservation}', [HotelFrontdeskController::class, 'storeReceiptPayment'])->name('hotel.frontdesk.receipt.store');
            Route::post('hotel/frontdesk/check-in/{reservation}', [HotelFrontdeskController::class, 'checkIn'])->name('hotel.frontdesk.check-in');
            Route::post('hotel/frontdesk/check-out/{stay}', [HotelFrontdeskController::class, 'checkOut'])->name('hotel.frontdesk.check-out');
            Route::post('hotel/frontdesk/extend-stay/{reservation}', [HotelFrontdeskController::class, 'extendStay'])->name('hotel.frontdesk.extend-stay');
            Route::post('hotel/frontdesk/change-room/{reservation}', [HotelFrontdeskController::class, 'changeRoom'])->name('hotel.frontdesk.change-room');
            Route::post('hotel/frontdesk/verify-minibar/{stay}', [HotelFrontdeskController::class, 'verifyMinibar'])->name('hotel.frontdesk.verify-minibar');
            Route::get('hotel/folios/{folio}', [HotelFolioController::class, 'show'])->name('hotel.folios.show');
            Route::get('hotel/folios/{folio}/final-bill', [HotelFolioController::class, 'finalBillPrint'])->name('hotel.folios.final-bill');
            Route::get('hotel/folios/{folio}/tax-invoice', [HotelFolioController::class, 'taxInvoicePdf'])->name('hotel.folios.tax-invoice');
            Route::get('hotel/night-audit', [HotelNightAuditController::class, 'index'])->name('hotel.night-audit.index');
            Route::get('hotel/night-audit/preview', [HotelNightAuditController::class, 'previewJson'])->name('hotel.night-audit.preview');
            Route::get('hotel/night-audit/export', [HotelNightAuditController::class, 'export'])->name('hotel.night-audit.export');
            Route::post('hotel/night-audit/run', [HotelNightAuditController::class, 'run'])->name('hotel.night-audit.run');

            Route::get('hotel/cashier-shifts', [HotelCashierShiftController::class, 'index'])->name('hotel.cashier-shifts.index');
            Route::post('hotel/cashier-shifts/open', [HotelCashierShiftController::class, 'open'])->name('hotel.cashier-shifts.open');
            Route::get('hotel/cashier-shifts/{shift}', [HotelCashierShiftController::class, 'show'])->name('hotel.cashier-shifts.show');
            Route::get('hotel/cashier-shifts/{shift}/close', [HotelCashierShiftController::class, 'closeForm'])->name('hotel.cashier-shifts.close-form');
            Route::post('hotel/cashier-shifts/{shift}/close', [HotelCashierShiftController::class, 'close'])->name('hotel.cashier-shifts.close');

            Route::resource('hotel/expenses', HotelExpenseController::class)->names('hotel.expenses')->except(['show']);

            Route::get('hotel/pos/in-house-guests', [HotelPosIntegrationController::class, 'inHouseGuests'])->name('hotel.pos.in-house-guests');
            Route::post('hotel/pos/charge', [HotelPosIntegrationController::class, 'charge'])->name('hotel.pos.charge');
        }
    );
});
