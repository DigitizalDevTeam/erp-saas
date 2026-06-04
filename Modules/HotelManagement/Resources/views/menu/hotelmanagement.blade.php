@can('hotel.menu.module')
<li class="dash-item dash-hasmenu {{ str_starts_with((string) Request::route()->getName(), 'hotel.') ? 'active dash-trigger' : '' }}">
    <a href="#!" class="dash-link">
        <span class="dash-micon"><i class="ti ti-home"></i></span>
        <span class="dash-mtext">{{ __('Hotel Management') }}</span>
        <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
    </a>
    <ul class="dash-submenu">
        @can('hotel.menu.dashboard')
        <li class="dash-item {{ Request::route()->getName() == 'hotel.dashboard' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.dashboard') }}">{{ __('Dashboard') }}</a>
        </li>
        @endcan
        @can('hotel.menu.reservations')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.reservations.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a>
        </li>
        @endcan
        @can('hotel.menu.frontdesk')
        <li class="dash-item {{ Request::route()->getName() == 'hotel.frontdesk.in-house' || str_starts_with((string) Request::route()->getName(), 'hotel.folios.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a>
        </li>
        @endcan
        @can('hotel.menu.guest_map')
        <li class="dash-item {{ Request::route()->getName() == 'hotel.guests.map' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.guests.map') }}">{{ __('Guest map') }}</a>
        </li>
        @endcan
        @can('hotel.menu.housekeeping')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.housekeeping.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.housekeeping.index') }}">{{ __('Housekeeping') }}</a>
        </li>
        <li class="dash-item {{ Request::route()->getName() === 'hotel.housekeeping.minibar' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.housekeeping.minibar') }}">{{ __('Daily minibar') }}</a>
        </li>
        @endcan
        @can('hotel.menu.night_audit')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.night-audit.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.night-audit.index') }}">{{ __('Night Audit') }}</a>
        </li>
        @endcan
        @can('hotel.menu.accounting')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.accounting-settings') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.accounting-settings.index') }}">{{ __('Hotel accounting (COA)') }}</a>
        </li>
        @endcan
        @can('hotel.menu.reports')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.reports.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.reports.operational') }}">{{ __('Hotel Reports') }}</a>
        </li>
        @endcan
        @can('hotel.menu.calendar')
        <li class="dash-item {{ Request::route()->getName() == 'hotel.calendar' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.calendar') }}">{{ __('Booking calendar') }}</a>
        </li>
        @endcan
        @can('hotel.menu.groups')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.groups.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.groups.index') }}">{{ __('Group reservations') }}</a>
        </li>
        @endcan
        @can('hotel.menu.ota')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.ota-settings.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.ota-settings.index') }}">{{ __('OTA / integrations') }}</a>
        </li>
        @endcan
        @can('hotel.menu.rooms_setup')
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.floors.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.floors.index') }}">{{ __('Floors') }}</a>
        </li>
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.room-types.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.room-types.index') }}">{{ __('Room Types') }}</a>
        </li>
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.rate-plans.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rate-plans.index') }}">{{ __('Rate Plans') }}</a>
        </li>
        <li class="dash-item {{ Request::route()->getName() === 'hotel.rooms.index' || (str_starts_with((string) Request::route()->getName(), 'hotel.rooms.') && Request::route()->getName() !== 'hotel.rooms.map') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a>
        </li>
        <li class="dash-item {{ Request::route()->getName() == 'hotel.rooms.map' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rooms.map') }}">{{ __('Room map') }}</a>
        </li>
        @endcan
    </ul>
</li>
@endcan
