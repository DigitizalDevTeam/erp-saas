@php
    $opsActive = request()->routeIs('hotel.reports.operational*') || request()->routeIs('hotel.reports.occupancy') || request()->routeIs('hotel.reports.ops.*');
    $finActive = request()->routeIs('hotel.reports.financial*');
    $guestActive = request()->routeIs('hotel.reports.immigration*') || request()->routeIs('hotel.reports.customers') || request()->routeIs('hotel.reports.alerts');
@endphp
<ul class="nav nav-pills flex-wrap gap-1 mb-3">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hotel.reports.index') ? 'active' : '' }}" href="{{ route('hotel.reports.index') }}">{{ __('Overview') }}</a>
    </li>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $opsActive ? 'active' : '' }}" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">{{ __('Operations') }}</a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('hotel.reports.operational', request()->only('from','to')) }}">{{ __('Operational summary') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.ops.reservations', request()->only('from','to')) }}">{{ __('Reservation Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.ops.check-in', request()->only('from','to')) }}">{{ __('Check-in Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.ops.check-out', request()->only('from','to')) }}">{{ __('Check-out Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.occupancy', request()->only('from','to')) }}">{{ __('Occupancy Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.ops.room-status') }}">{{ __('Room Status Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.ops.housekeeping', request()->only('from','to')) }}">{{ __('Housekeeping Report') }}</a></li>
        </ul>
    </li>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $finActive ? 'active' : '' }}" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">{{ __('Financial') }}</a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial', request()->only('from','to')) }}">{{ __('Financial summary') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.daily-revenue', request()->only('from','to')) }}">{{ __('Daily Revenue Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.monthly-revenue', request()->only('from','to')) }}">{{ __('Monthly Revenue Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.payment-collection', request()->only('from','to')) }}">{{ __('Payment Collection Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.outstanding') }}">{{ __('Outstanding Balance Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.cashier-closing', request()->only('from','to')) }}">{{ __('Cashier Closing Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.financial.occupancy-revenue', request()->only('from','to')) }}">{{ __('Occupancy Revenue Report') }}</a></li>
        </ul>
    </li>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $guestActive ? 'active' : '' }}" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">{{ __('Guests & compliance') }}</a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('hotel.reports.immigration', request()->only('from','to')) }}">{{ __('Immigration Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.customers', request()->only('from','to')) }}">{{ __('Customer Report') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('hotel.reports.alerts') }}">{{ __('Alerts & performance') }}</a></li>
        </ul>
    </li>
</ul>
