@can('hotel.menu.module')
@php
    $routeName = (string) Request::route()->getName();
    $frontOfficeActive = str_starts_with($routeName, 'hotel.reservations.')
        || str_starts_with($routeName, 'hotel.frontdesk.')
        || str_starts_with($routeName, 'hotel.folios.')
        || str_starts_with($routeName, 'hotel.guests.')
        || str_starts_with($routeName, 'hotel.calendar')
        || str_starts_with($routeName, 'hotel.groups.');
    $roomManagementActive = str_starts_with($routeName, 'hotel.floors.')
        || str_starts_with($routeName, 'hotel.rooms.')
        || str_starts_with($routeName, 'hotel.room-types.');
    $operationsActive = str_starts_with($routeName, 'hotel.housekeeping.') || $roomManagementActive;
    $salesBookingActive = str_starts_with($routeName, 'hotel.booking-site.')
        || str_starts_with($routeName, 'hotel.rate-plans.')
        || str_starts_with($routeName, 'hotel.addons.');
    $financeActive = str_starts_with($routeName, 'hotel.cashier-shifts.')
        || str_starts_with($routeName, 'hotel.expenses.')
        || str_starts_with($routeName, 'hotel.accounting-settings');
    $reportsActive = str_starts_with($routeName, 'hotel.reports.')
        || str_starts_with($routeName, 'hotel.night-audit.');
    $settingsActive = str_starts_with($routeName, 'hotel.ota-settings.');
@endphp
<li class="dash-item dash-hasmenu {{ str_starts_with($routeName, 'hotel.') ? 'active dash-trigger' : '' }}">
    <a href="#!" class="dash-link">
        <span class="dash-micon"><i class="ti ti-home"></i></span>
        <span class="dash-mtext">{{ __('Hotel Management') }}</span>
        <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
    </a>
    <ul class="dash-submenu">
        @can('hotel.menu.dashboard')
        <li class="dash-item {{ $routeName == 'hotel.dashboard' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.dashboard') }}">{{ __('Dashboard') }}</a>
        </li>
        @endcan

        @if (Gate::check('hotel.menu.reservations') || Gate::check('hotel.menu.frontdesk') || Gate::check('hotel.menu.guest_map') || Gate::check('hotel.menu.calendar') || Gate::check('hotel.menu.groups'))
        <li class="dash-item dash-hasmenu {{ $frontOfficeActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Front Office') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                @can('hotel.menu.reservations')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.reservations.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a>
                </li>
                @endcan
                @can('hotel.menu.frontdesk')
                <li class="dash-item {{ $routeName == 'hotel.frontdesk.in-house' || str_starts_with($routeName, 'hotel.folios.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Reception') }}</a>
                </li>
                @endcan
                @can('hotel.menu.guest_map')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.guests.') && $routeName !== 'hotel.guests.map' ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.guests.index') }}">{{ __('Guests') }}</a>
                </li>
                <li class="dash-item {{ $routeName == 'hotel.guests.map' ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.guests.map') }}">{{ __('Guest Map') }}</a>
                </li>
                @endcan
                @can('hotel.menu.calendar')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.calendar') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.calendar') }}">{{ __('Booking Calendar') }}</a>
                </li>
                @endcan
                @can('hotel.menu.groups')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.groups.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.groups.index') }}">{{ __('Group Bookings') }}</a>
                </li>
                @endcan
            </ul>
        </li>
        @endif

        @if (Gate::check('hotel.menu.housekeeping') || Gate::check('hotel.menu.rooms_setup'))
        <li class="dash-item dash-hasmenu {{ $operationsActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Operations') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                @can('hotel.menu.housekeeping')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.housekeeping.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.housekeeping.index') }}">{{ __('Housekeeping') }}</a>
                </li>
                <li class="dash-item {{ $routeName === 'hotel.housekeeping.minibar' ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.housekeeping.minibar') }}">{{ __('Daily Minibar') }}</a>
                </li>
                @endcan
                @can('hotel.menu.rooms_setup')
                <li class="dash-item dash-hasmenu {{ $roomManagementActive ? 'active dash-trigger' : '' }}">
                    <a class="dash-link" href="#">{{ __('Room Management') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                    <ul class="dash-submenu">
                        <li class="dash-item {{ str_starts_with($routeName, 'hotel.floors.') ? 'active' : '' }}">
                            <a class="dash-link" href="{{ route('hotel.floors.index') }}">{{ __('Floors') }}</a>
                        </li>
                        <li class="dash-item {{ $routeName === 'hotel.rooms.index' || (str_starts_with($routeName, 'hotel.rooms.') && $routeName !== 'hotel.rooms.map') ? 'active' : '' }}">
                            <a class="dash-link" href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a>
                        </li>
                        <li class="dash-item {{ $routeName == 'hotel.rooms.map' ? 'active' : '' }}">
                            <a class="dash-link" href="{{ route('hotel.rooms.map') }}">{{ __('Room Map') }}</a>
                        </li>
                        <li class="dash-item {{ str_starts_with($routeName, 'hotel.room-types.') ? 'active' : '' }}">
                            <a class="dash-link" href="{{ route('hotel.room-types.index') }}">{{ __('Room Types') }}</a>
                        </li>
                    </ul>
                </li>
                @endcan
            </ul>
        </li>
        @endif

        @if (Gate::check('hotel.menu.booking_site') || Gate::check('hotel.menu.rooms_setup'))
        <li class="dash-item dash-hasmenu {{ $salesBookingActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Sales / Booking') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                @can('hotel.menu.booking_site')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.booking-site.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.booking-site.edit') }}">{{ __('Booking Site') }}</a>
                </li>
                @endcan
                @can('hotel.menu.rooms_setup')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.rate-plans.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.rate-plans.index') }}">{{ __('Pricing Plans') }}</a>
                </li>
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.addons.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.addons.index') }}">{{ __('Add-ons & Extras') }}</a>
                </li>
                @endcan
            </ul>
        </li>
        @endif

        @if (Gate::check('hotel.menu.frontdesk') || Gate::check('hotel.menu.accounting'))
        <li class="dash-item dash-hasmenu {{ $financeActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Finance') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                @can('hotel.menu.frontdesk')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.cashier-shifts.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.cashier-shifts.index') }}">{{ __('Cashier Shifts') }}</a>
                </li>
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.expenses.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.expenses.index') }}">{{ __('Hotel Expenses') }}</a>
                </li>
                @endcan
                @can('hotel.menu.accounting')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.accounting-settings') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.accounting-settings.index') }}">{{ __('Hotel Accounting (COA)') }}</a>
                </li>
                @endcan
            </ul>
        </li>
        @endif

        @if (Gate::check('hotel.menu.reports') || Gate::check('hotel.menu.night_audit'))
        <li class="dash-item dash-hasmenu {{ $reportsActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Reports') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                @can('hotel.menu.reports')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.reports.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.reports.index') }}">{{ __('Hotel Reports') }}</a>
                </li>
                @endcan
                @can('hotel.menu.night_audit')
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.night-audit.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.night-audit.index') }}">{{ __('Night Audit') }}</a>
                </li>
                @endcan
            </ul>
        </li>
        @endif

        @can('hotel.menu.ota')
        <li class="dash-item dash-hasmenu {{ $settingsActive ? 'active dash-trigger' : '' }}">
            <a class="dash-link" href="#">{{ __('Settings') }}<span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
            <ul class="dash-submenu">
                <li class="dash-item {{ str_starts_with($routeName, 'hotel.ota-settings.') ? 'active' : '' }}">
                    <a class="dash-link" href="{{ route('hotel.ota-settings.index') }}">{{ __('OTA / Integrations') }}</a>
                </li>
            </ul>
        </li>
        @endcan
    </ul>
</li>
@endcan
