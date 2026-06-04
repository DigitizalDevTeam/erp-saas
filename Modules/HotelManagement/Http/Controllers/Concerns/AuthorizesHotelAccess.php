<?php

namespace Modules\HotelManagement\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Modules\HotelManagement\Services\HotelAuthorization;

trait AuthorizesHotelAccess
{
    protected function denyHotelUnless(bool $allowed): RedirectResponse|null
    {
        if (! $allowed) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return null;
    }

    protected function authorizeHotelView(callable $check): RedirectResponse|null
    {
        return $this->denyHotelUnless($check(HotelAuthorization::user()));
    }

    protected function authorizeHotelManage(callable $check): RedirectResponse|null
    {
        return $this->denyHotelUnless($check(HotelAuthorization::user()));
    }
}
