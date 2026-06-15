<ul class="nav nav-pills flex-wrap gap-1 mb-3">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.index') ? 'active' : '' }}" href="{{ route('hotel.reports.index') }}">{{ __('Overview') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.operational*') ? 'active' : '' }}" href="{{ route('hotel.reports.operational', request()->query()) }}">{{ __('Operational') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.financial*') ? 'active' : '' }}" href="{{ route('hotel.reports.financial', request()->query()) }}">{{ __('Financial') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.immigration*') ? 'active' : '' }}" href="{{ route('hotel.reports.immigration', request()->query()) }}">{{ __('Immigration') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.occupancy') ? 'active' : '' }}" href="{{ route('hotel.reports.occupancy', request()->query()) }}">{{ __('Occupancy') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.customers') ? 'active' : '' }}" href="{{ route('hotel.reports.customers', request()->query()) }}">{{ __('Customers') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('hotel.reports.alerts') ? 'active' : '' }}" href="{{ route('hotel.reports.alerts') }}">{{ __('Alerts') }}</a></li>
</ul>
