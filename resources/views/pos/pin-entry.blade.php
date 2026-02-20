@php
    $setting = \App\Models\Utility::colorset();
    $color = $setting['color'] ?? 'theme-3';
    if (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $themeColor = 'theme-3';
    } else {
        $themeColor = $color;
    }
    $SITE_RTL = \App\Models\Utility::getValByName('SITE_RTL');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - {{ __('Restaurant POS') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    @if ($SITE_RTL == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}">
    @endif
    @if (($setting['cust_darklayout'] ?? '') == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}">
    <style>
        .pin-page { min-height: 100vh; display: flex; flex-direction: column; }
        .pin-top-bar { padding: 0.6rem 1rem; border-radius: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .pin-card { max-width: 380px; width: 100%; margin: 2rem auto; }
        .pin-input { font-size: 1.5rem; letter-spacing: 0.4rem; text-align: center; }
    </style>
</head>
<body class="{{ $themeColor }}">
    <div class="pin-page container-fluid px-2">
        <div class="row">
            <div class="col-12">
                <div class="mt-2 pos-top-bar pin-top-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-bold" style="font-size: 1.25rem;">{{ __('Restaurant POS') }}</span>
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light"><i class="ti ti-arrow-left"></i> {{ __('Back to Dashboard') }}</a>
                </div>
            </div>
        </div>
        <div class="row flex-grow-1 align-items-center justify-content-center">
            <div class="col-12">
                <div class="pin-card card border-0 shadow-sm mx-auto">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <i class="ti ti-building-store text-success" style="font-size: 2.5rem;"></i>
                            <h5 class="mt-3 mb-1">{{ __('Enter your PIN') }}</h5>
                            <p class="text-muted small mb-0">{{ __('Use the PIN assigned by your manager.') }}</p>
                        </div>
                        <form id="pos-pin-form" method="post" action="{{ route('pos.restaurant.validate-pin') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="pos_pin" class="form-label">{{ __('PIN') }}</label>
                                <input type="password" name="pin" id="pos_pin" class="form-control form-control-lg pin-input" placeholder="••••" maxlength="8" minlength="4" autocomplete="off" required autofocus inputmode="numeric" pattern="[0-9]*">
                                <div id="pos-pin-error" class="invalid-feedback d-none"></div>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100" id="pos-pin-submit">
                                <i class="ti ti-login me-1"></i> {{ __('Continue') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script>
        (function() {
            var form = document.getElementById('pos-pin-form');
            var input = document.getElementById('pos_pin');
            var submit = document.getElementById('pos-pin-submit');
            var errEl = document.getElementById('pos-pin-error');
            if (!form || !input) return;
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 8);
                errEl.classList.add('d-none');
                input.classList.remove('is-invalid');
            });
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var pin = input.value.trim();
                if (pin.length < 4) {
                    errEl.textContent = '{{ __("PIN must be 4-8 digits.") }}';
                    errEl.classList.remove('d-none');
                    input.classList.add('is-invalid');
                    return;
                }
                submit.disabled = true;
                var fd = new FormData(form);
                fd.set('pin', pin);
                fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        window.location.href = '{{ route("pos.restaurant.index") }}';
                        return;
                    }
                    errEl.textContent = data.error || data.message || '{{ __("Invalid PIN.") }}';
                    errEl.classList.remove('d-none');
                    input.classList.add('is-invalid');
                    submit.disabled = false;
                }).catch(function() {
                    errEl.textContent = '{{ __("pos.error") }}';
                    errEl.classList.remove('d-none');
                    input.classList.add('is-invalid');
                    submit.disabled = false;
                });
            });
        })();
    </script>
</body>
</html>
