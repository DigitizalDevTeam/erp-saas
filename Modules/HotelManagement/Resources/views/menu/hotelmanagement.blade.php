@if (Gate::check('manage hotel') || Gate::check('show hotel dashboard') || Gate::check('manage hotel room') || Gate::check('show hotel room') || Gate::check('manage hotel reservation') || Gate::check('show hotel reservation') || Gate::check('manage hotel frontdesk') || Gate::check('show hotel frontdesk') || Gate::check('show hotel folio'))
<li class="dash-item dash-hasmenu {{ str_starts_with((string) Request::route()->getName(), 'hotel.') ? 'active dash-trigger' : '' }}">
    <a href="#!" class="dash-link">
        <span class="dash-micon"><i class="ti ti-home"></i></span>
        <span class="dash-mtext">{{ __('Hotel Management') }}</span>
        <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
    </a>
    <ul class="dash-submenu">
        @if (Gate::check('manage hotel') || Gate::check('show hotel dashboard'))
        <li class="dash-item {{ Request::route()->getName() == 'hotel.dashboard' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.dashboard') }}">{{ __('Dashboard') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel room') || Gate::check('show hotel room'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.floors.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.floors.index') }}">{{ __('Floors') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel room') || Gate::check('show hotel room'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.room-types.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.room-types.index') }}">{{ __('Room Types') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel room') || Gate::check('show hotel room'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.rate-plans.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rate-plans.index') }}">{{ __('Rate Plans') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel room') || Gate::check('show hotel room'))
        <li class="dash-item {{ Request::route()->getName() === 'hotel.rooms.index' || (str_starts_with((string) Request::route()->getName(), 'hotel.rooms.') && Request::route()->getName() !== 'hotel.rooms.map') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel room') || Gate::check('show hotel room'))
        <li class="dash-item {{ Request::route()->getName() == 'hotel.rooms.map' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.rooms.map') }}">{{ __('Mapa de Quartos') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel reservation') || Gate::check('show hotel reservation') || Gate::check('manage hotel frontdesk') || Gate::check('show hotel frontdesk'))
        <li class="dash-item {{ Request::route()->getName() == 'hotel.guests.map' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.guests.map') }}">{{ __('Mapa de Hospedes') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel reservation') || Gate::check('show hotel reservation'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.reservations.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('show hotel dashboard'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.reports.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.reports.operational') }}">{{ __('Hotel Reports') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel reservation') || Gate::check('show hotel reservation'))
        <li class="dash-item {{ Request::route()->getName() == 'hotel.calendar' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.calendar') }}">{{ __('Booking calendar') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel reservation') || Gate::check('show hotel reservation'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.groups.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.groups.index') }}">{{ __('Group reservations') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel reservation'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.ota-settings.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.ota-settings.index') }}">{{ __('OTA / integrations') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel frontdesk') || Gate::check('show hotel frontdesk'))
        <li class="dash-item {{ Request::route()->getName() == 'hotel.frontdesk.in-house' ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel frontdesk') || Gate::check('show hotel frontdesk'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.housekeeping.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.housekeeping.index') }}">{{ __('Housekeeping') }}</a>
        </li>
        @endif
        @if (Gate::check('manage hotel') || Gate::check('manage hotel frontdesk') || Gate::check('show hotel frontdesk'))
        <li class="dash-item {{ str_starts_with((string) Request::route()->getName(), 'hotel.night-audit.') ? 'active' : '' }}">
            <a class="dash-link" href="{{ route('hotel.night-audit.index') }}">{{ __('Night Audit') }}</a>
        </li>
        @endif
    </ul>
</li>
@endif
