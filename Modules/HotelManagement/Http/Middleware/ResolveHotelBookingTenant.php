<?php

namespace Modules\HotelManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Symfony\Component\HttpFoundation\Response;

class ResolveHotelBookingTenant
{
    public function handle(Request $request, Closure $next, string $requirePublished = '1'): Response
    {
        $slug = strtolower((string) $request->route('hotelSlug'));
        if ($slug === '' || HotelBookingSite::isReservedSlug($slug)) {
            abort(404);
        }

        $site = HotelBookingSite::where('slug', $slug)->first();
        if (! $site) {
            abort(404);
        }

        if ($requirePublished === '1' && ! $site->is_published) {
            abort(404);
        }

        $request->attributes->set('hotel_booking_site', $site);
        $request->attributes->set('hotel_tenant_id', (int) $site->created_by);

        if ($site->default_language) {
            app()->setLocale($site->default_language);
        }

        return $next($request);
    }
}
