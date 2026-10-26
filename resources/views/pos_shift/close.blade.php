@extends('layouts.admin')
@section('page-title')
    {{ __('Close Shift') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Close Shift') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8 col-xl-6">
            <div class="card">
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                    <p class="mb-3"><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                    <div class="mb-3">
                        <a href="{{ route('report.restaurant.sales-by-category', ['scope' => 'shift', 'shift_id' => $pos_shift->id, 'with_stock' => 1]) }}" class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="ti ti-category"></i> {{ __('Sales by Categories') }}
                        </a>
                    </div>
                    {{ Form::open(['route' => ['pos-shift.close', $pos_shift], 'method' => 'post', 'class' => 'needs-validation', 'novalidate', 'id' => 'pos-shift-close-form']) }}
                    @include('pos_shift.partials.close_count', [
                        'closeShift' => $pos_shift,
                        'openingBalance' => $pos_shift->opening_balance ?? 0,
                        'salesTotal' => $salesTotal ?? 0,
                        'expectedCash' => $expectedCash ?? 0,
                        'amountField' => 'closing_balance',
                        'reportWarehouses' => $reportWarehouses ?? [],
                    ])
                    <div class="form-group mt-3">
                        {{ Form::label('tips', __('Tips'), ['class' => 'form-label']) }}
                        {{ Form::number('tips', old('tips', $pos_shift->tips ?? 0), ['class' => 'form-control', 'min' => 0, 'step' => '0.01']) }}
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-danger" id="pos-shift-close-submit">{{ __('Close Shift') }}</button>
                        <a href="{{ route('pos-shift.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                    <script src="{{ asset('js/shift-close-count.js') }}"></script>
                    <script>
                        document.querySelectorAll('.shift-close-count').forEach(function (el) {
                            if (window.initShiftCloseCount) window.initShiftCloseCount(el);
                        });
                        (function () {
                            var form = document.getElementById('pos-shift-close-form');
                            var btn = document.getElementById('pos-shift-close-submit');
                            if (!form || !btn) return;
                            form.addEventListener('submit', function () {
                                if (form.querySelector('.shift-close-reason') && form.querySelector('.shift-close-reason').required && !form.querySelector('.shift-close-reason').value.trim()) {
                                    return;
                                }
                                btn.disabled = true;
                                btn.innerText = @json(__('Closing...'));
                            });
                        })();
                    </script>
                </div>
            </div>
        </div>
    </div>
@endsection
