@php
    $users=\Auth::user();
    $profile=\App\Models\Utility::get_file('uploads/avatar/');
    $languages=\App\Models\Utility::languages();
    $lang = isset($users->lang)?$users->lang:'en';
    if ($lang == null) {
        $lang = 'en';
    }
    $LangName = cache()->remember('full_language_data_' . $lang, now()->addHours(24), function () use ($lang) {
        return \App\Models\Language::languageData($lang);
    });
    if (empty($LangName)) {
        $LangName = (object) ['full_name' => strtoupper($lang)];
    }

    $setting = \App\Models\Utility::settings();

    $unseenCounter=App\Models\ChMessage::where('to_id', Auth::user()->id)->where('seen', 0)->count();

    // Generic header notification bell: always visible, counter always
    // shown like the chat counter (including 0). Today its feed is
    // low-stock alerts; future notification
    // types plug into this same dropdown, not a second bell.
    // Computed here (like the chat counter) so every admin page carries it.
    $lowStockUnreadCount = 0;
    $lowStockNotifications = collect();
    if (\Auth::check() && \Auth::user()->type !== 'client' && \Auth::user()->type !== 'super admin') {
        $lowStockUnreadCount = \App\Services\LowStockNotificationService::unreadCountForUser(\Auth::user());
        $lowStockNotifications = \App\Services\LowStockNotificationService::recentForUser(\Auth::user(), 10);
    }

    $branchSwitcherBranches = collect();
    $companySwitcherCompanies = collect();
    if (\Auth::check() && \Auth::user()->type !== 'super admin') {
        $branchSwitcherBranches = \Auth::user()->branchesForSelection();
        $companySwitcherCompanies = \Auth::user()->companiesForSelection();
    }
@endphp
@if (isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on')
    <header class="dash-header transprent-bg">
@else
    <header class="dash-header">
@endif
    <div class="header-wrapper">
        <div class="me-auto dash-mob-drp">
            <ul class="list-unstyled">
                <li class="dash-h-item mob-hamburger">
                    <a href="#!" class="dash-head-link" id="mobile-collapse">
                        <div class="hamburger hamburger--arrowturn">
                            <div class="hamburger-box">
                                <div class="hamburger-inner"></div>
                            </div>
                        </div>
                    </a>
                </li>
                <li class="dash-h-item sidebar-mini-toggle">
                    <a href="#!" class="dash-head-link" id="vertical-nav-toggle" title="{{ __('Toggle sidebar') }}">
                        <i class="ti ti-layout-sidebar"></i>
                    </a>
                </li>

                <li class="dropdown dash-h-item drp-company">
                    <a class="dash-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <span class="theme-avtar">
                             <img src="{{ !empty(\Auth::user()->avatar) ? $profile . \Auth::user()->avatar :  $profile.'avatar.png'}}" class="img-fluid rounded border-2 border border-primary">
                        </span>
                        <span class="hide-mob ms-2">{{__('Hi, ')}}{{\Auth::user()->name }}!</span>
                        <i class="ti ti-chevron-down drp-arrow nocolor hide-mob"></i>
                    </a>
                    <div class="dropdown-menu dash-h-dropdown">

                        <a href="{{route('profile')}}" class="dropdown-item">
                            <i class="ti ti-user text-dark"></i><span>{{__('Profile')}}</span>
                        </a>

                        <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('frm-logout').submit();" class="dropdown-item">
                            <i class="ti ti-power text-dark"></i><span>{{__('Logout')}}</span>
                        </a>

                        <form id="frm-logout" action="{{ route('logout') }}" method="POST" class="d-none">
                            {{ csrf_field() }}
                        </form>

                    </div>
                </li>

            </ul>
        </div>
        <div class="ms-auto">
            <ul class="list-unstyled">
                @if($branchSwitcherBranches->count() > 1)
                    <li class="dash-h-item d-none d-md-flex align-items-center me-2">
                        <form action="{{ route('user.branch-context') }}" method="post" class="d-flex align-items-center gap-2 mb-0">
                            @csrf
                            <label for="active-branch-switch" class="mb-0 small text-muted hide-mob">{{ __('Branch') }}</label>
                            <select name="branch_id" id="active-branch-switch" class="form-select form-select-sm" style="min-width: 10rem;" onchange="this.form.submit()">
                                @foreach ($branchSwitcherBranches as $switchBranch)
                                    <option value="{{ $switchBranch->id }}" @selected((int) \Auth::user()->activeBranchContextId() === (int) $switchBranch->id)>{{ $switchBranch->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                @endif
                @if($companySwitcherCompanies->count() > 1)
                    <li class="dash-h-item d-none d-md-flex align-items-center me-2">
                        <form action="{{ route('user.company-context') }}" method="post" class="d-flex align-items-center gap-2 mb-0">
                            @csrf
                            <label for="active-company-switch" class="mb-0 small text-muted hide-mob">{{ __('Company') }}</label>
                            <select name="company_id" id="active-company-switch" class="form-select form-select-sm" style="min-width: 13rem;" onchange="this.form.submit()">
                                @foreach ($companySwitcherCompanies as $switchCompany)
                                    <option value="{{ $switchCompany->id }}" @selected((int) \Auth::user()->activeCompanyId() === (int) $switchCompany->id)>{{ $switchCompany->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                @endif
                @if(\Auth::user()->type == 'company' )
                @impersonating($guard = null)
                <li class="dropdown dash-h-item drp-company">
                    <a class="btn btn-danger btn-sm" href="{{ route('exit.company') }}"><i class="ti ti-ban"></i>
                        {{ __('Exit Company Login') }}
                    </a>
                </li>
                @endImpersonating
                @endif

                @if(\Auth::check() && \Auth::user()->canManagePrinters())
                    <li class="dash-h-item">
                        <a href="javascript:void(0)" class="dash-head-link" id="global-printer-settings-btn" title="{{ __('Printer Settings') }}" style="background-color:#dc3545;">
                            <i class="ti ti-printer" style="color:#fff;"></i>
                        </a>
                    </li>
                @endif
                @if( \Auth::user()->type !='client' && \Auth::user()->type !='super admin' )
                    <li class="dropdown dash-h-item drp-notification">
                        <a class="dash-head-link arrow-none me-0" href="{{ url('chats') }}" aria-haspopup="false"
                           aria-expanded="false">
                            <i class="ti ti-brand-hipchat"></i>
                            <span class="bg-danger dash-h-badge message-toggle-msg  message-counter custom_messanger_counter beep"> {{ $unseenCounter }}<span
                                    class="sr-only"></span>
                            </span>
                        </a>
                    </li>
                @endif
                @if( \Auth::check() && \Auth::user()->type !='client' && \Auth::user()->type !='super admin' )
                    <li class="dropdown dash-h-item drp-notification">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false" title="{{ __('Notifications') }}">
                            <i class="ti ti-bell"></i>
                            <span id="notif-badge" class="bg-danger dash-h-badge"> {{ $lowStockUnreadCount }}<span class="sr-only"></span></span>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end" style="width:360px;max-height:420px;overflow-y:auto;">
                            <div class="d-flex justify-content-between align-items-center px-3 pt-2 pb-1">
                                <strong>{{ __('Notifications') }}</strong>
                                <a href="#" id="notif-mark-all" class="small text-primary">{{ __('Mark all read') }}</a>
                            </div>
                            <div id="notif-list">
                                @forelse ($lowStockNotifications as $n)
                                    <a href="#" class="dropdown-item notif-item {{ $n->is_read ? '' : 'fw-bold' }}" data-id="{{ $n->id }}" title="{{ $n->created_at ? $n->created_at->diffForHumans() : '' }}">
                                        <span class="d-block text-truncate">{{ __('Low stock') }}: {{ $n->product->name ?? __('Product') }} — {{ $n->warehouse->name ?? '' }} · {{ __('Qty') }} {{ (float) $n->quantity }} / {{ __('Low') }} {{ $n->low_stock_threshold }}@if($n->resolved_at) · {{ __('Recovered') }}@endif</span>
                                    </a>
                                @empty
                                    <span class="dropdown-item text-muted">{{ __('No notifications.') }}</span>
                                @endforelse
                            </div>
                        </div>
                    </li>
                @endif

                <li class="dropdown dash-h-item drp-language">
                    <a
                        class="dash-head-link dropdown-toggle arrow-none me-0"
                        data-bs-toggle="dropdown"
                        href="#"
                        role="button"
                        aria-haspopup="false"
                        aria-expanded="false"
                    >
                        <i class="ti ti-world nocolor"></i>
                        <span class="drp-text hide-mob">{{ucfirst($LangName->full_name)}}</span>
                        <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                    </a>
                    <div class="dropdown-menu dash-h-dropdown dropdown-menu-end">
                        @foreach ($languages as $code => $language)
                            <a href="{{ route('change.language', $code) }}"
                               class="dropdown-item {{ $lang == $code ? 'text-primary' : '' }}">
                                <span>{{ucFirst($language)}}</span>
                            </a>
                        @endforeach

                        <h></h>
                            @if(\Auth::user()->type=='super admin')
                                <a data-url="{{ route('create.language') }}" class="dropdown-item text-primary" data-ajax-popup="true" data-title="{{__('Create New Language')}}" style="cursor: pointer">
                                    {{ __('Create Language') }}
                                </a>
                                <a class="dropdown-item text-primary" href="{{route('manage.language',[isset($lang)?$lang:'english'])}}">{{ __('Manage Language') }}</a>
                            @endif
                    </div>
                </li>
            </ul>
        </div>
    </div>
    </header>
<script>
    // Notification bell actions (vanilla JS: this partial renders before
    // jQuery is loaded at the page bottom, so no $ dependency here).
    (function () {
        var base = '{{ url('low-stock-notifications') }}';
        function csrf() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }
        // Same as the chat counter: the number is always shown, even 0.
        function setBadge(n) {
            var b = document.getElementById('notif-badge');
            if (!b) return;
            b.textContent = ' ' + n;
            b.style.display = '';
        }
        function post(url) {
            return fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); });
        }
        document.querySelectorAll('.notif-item').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                post(base + '/' + el.getAttribute('data-id') + '/read').then(function (data) {
                    el.classList.remove('fw-bold');
                    if (data && typeof data.unread_count !== 'undefined') setBadge(data.unread_count);
                }).catch(function () {});
            });
        });
        var markAll = document.getElementById('notif-mark-all');
        if (markAll) {
            markAll.addEventListener('click', function (e) {
                e.preventDefault();
                post(base + '/read-all').then(function () {
                    document.querySelectorAll('.notif-item').forEach(function (el) { el.classList.remove('fw-bold'); });
                    setBadge(0);
                }).catch(function () {});
            });
        }
    })();
</script>
