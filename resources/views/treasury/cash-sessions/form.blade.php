@extends('layouts.admin')
@section('page-title')
    {{ __('Open Cash Session') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.index') }}">{{ __('Cash Registers') }}</a></li>
    <li class="breadcrumb-item">{{ __('Open') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($registers->isEmpty())
                        <p class="text-muted">{{ __('A session opens on a cash-register account. Create a cash-register account first.') }}</p>
                        <a href="{{ route('treasury-cash-sessions.index') }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @else
                        <form method="POST" action="{{ route('treasury-cash-sessions.store') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Cash Register') }} <span class="text-danger">*</span></label>
                                    <select name="register_id" id="register_id" class="form-control" required>
                                        <option value="">{{ __('Select a register') }}</option>
                                        @foreach ($registers as $register)
                                            <option value="{{ $register->id }}" data-currency="{{ $register->currency }}" {{ (int) old('register_id') === (int) $register->id ? 'selected' : '' }}>{{ $register->displayName() }} — {{ $register->currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Operator') }} <span class="text-danger">*</span></label>
                                    <select name="operator_user_id" class="form-control" required>
                                        <option value="">{{ __('Select the operator') }}</option>
                                        @foreach ($operators as $operator)
                                            <option value="{{ $operator->id }}" {{ (int) old('operator_user_id') === (int) $operator->id ? 'selected' : '' }}>{{ $operator->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Opening Float') }} <span class="text-danger">*</span></label>
                                    <input type="number" step="0.0001" min="0" name="opening_float" class="form-control" value="{{ old('opening_float', '') }}" required>
                                    <small class="text-muted">{{ __('The cash already on the register account — an approved opening balance or a transfer in. A float that differs from the register balance is flagged, not corrected.') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('POS Shift (optional)') }}</label>
                                    <select name="shift_id" class="form-control">
                                        <option value="">{{ __('Open manually — no shift') }}</option>
                                        @foreach ($shifts as $shift)
                                            <option value="{{ $shift->id }}" {{ (int) old('shift_id') === (int) $shift->id ? 'selected' : '' }}>{{ __('Shift') }} #{{ $shift->id }} — {{ $shift->closed_at ? $shift->closed_at->format('Y-m-d') : '' }} — {{ __('cash') }} {{ number_format((float) $shift->cash_total, 2) }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('A closed shift feeds the session once: its cash takings arrive as one inflow movement.') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Branch') }}</label>
                                    <input type="number" name="branch_id" class="form-control" value="{{ old('branch_id', '') }}">
                                </div>
                            </div>
                            <div id="fx_wrap" style="display: none;">
                                <h6 class="mt-2">{{ __('Exchange Rate (register → :base)', ['base' => $baseCurrency]) }}</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate') }} <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0000000001" min="0" name="exchange_rate" id="exchange_rate" class="form-control" value="{{ old('exchange_rate', '') }}">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate Source') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="rate_source" class="form-control" value="{{ old('rate_source', '') }}" maxlength="20" placeholder="bank / central bank / manual">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate Date') }}</label>
                                        <input type="date" name="rate_date" class="form-control" value="{{ old('rate_date', date('Y-m-d')) }}">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('Open Session') }}</button>
                            <a href="{{ route('treasury-cash-sessions.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        (function () {
            var baseCurrency = '{{ $baseCurrency }}';
            var registerSelect = document.getElementById('register_id');
            var fxWrap = document.getElementById('fx_wrap');

            function refresh() {
                var option = registerSelect.options[registerSelect.selectedIndex];
                var currency = option ? (option.getAttribute('data-currency') || '') : '';
                fxWrap.style.display = (currency !== '' && currency !== baseCurrency) ? '' : 'none';
            }

            registerSelect.addEventListener('change', refresh);
            refresh();
        })();
    </script>
@endpush
