<?php

use Illuminate\Support\Facades\Route;
use Modules\HotelManagement\Http\Controllers\PublicHotelBookingController;

/*
| Public direct booking — register last (via HotelManagementServiceProvider booted).
| URL: /{hotelSlug}
*/

Route::middleware(['web', 'hotel.booking.tenant'])
    ->where(['hotelSlug' => '[a-z0-9][a-z0-9\-]{0,62}[a-z0-9]'])
    ->prefix('{hotelSlug}')
    ->group(function () {
        Route::get('/', [PublicHotelBookingController::class, 'home'])->name('hotel.public.home');
        Route::get('/search', [PublicHotelBookingController::class, 'search'])->name('hotel.public.search');
        Route::post('/availability', [PublicHotelBookingController::class, 'availabilityJson'])->name('hotel.public.availability');
        Route::get('/book', [PublicHotelBookingController::class, 'bookForm'])->name('hotel.public.book');
        Route::post('/book', [PublicHotelBookingController::class, 'storeBooking'])->name('hotel.public.book.store');
        Route::get('/book/stripe/success', [PublicHotelBookingController::class, 'stripeSuccess'])->name('hotel.public.stripe.success');
        Route::get('/book/stripe/cancel', [PublicHotelBookingController::class, 'stripeCancel'])->name('hotel.public.stripe.cancel');
        Route::get('/confirmation/{token}', [PublicHotelBookingController::class, 'confirmation'])
            ->name('hotel.public.confirmation')
            ->middleware('hotel.booking.tenant:0');
    });
