@extends('layouts.admin')
@section('page-title')
    {{ __('Plan the Week') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen-daily-specials.index') }}">{{ __('Daily Specials') }}</a></li>
    <li class="breadcrumb-item">{{ __('Plan the Week') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen-daily-specials.plan') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Week of (Monday)') }}</label>
                            <input type="date" name="week_start" value="{{ $week_start }}" class="form-control">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Show Week') }}</button>
                        </div>
                    </form>

                    <p class="text-muted">{{ __('Plan the plate of the day for each day of the week. Monday to Friday usually have specials; Saturday and Sunday are off by default — just leave them empty. Several specials per day are fine.') }}</p>

                    <form method="POST" action="{{ route('kitchen-daily-specials.store-plan') }}">
                        @csrf
                        <input type="hidden" name="week_start" value="{{ $week_start }}">

                        @foreach ($week_days as $day)
                            @php
                                $dayName = date('l', strtotime($day));
                                $daySpecials = $existing[$day] ?? collect();
                                $isWeekend = in_array($dayName, ['Saturday', 'Sunday'], true);
                            @endphp
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0">{{ $dayName }} — {{ date('d M Y', strtotime($day)) }} {{ $isWeekend ? '(' . __('weekend — off by default') . ')' : '' }}</h6>
                                </div>
                                <div class="card-body">
                                    @if ($daySpecials->isNotEmpty())
                                        <p class="small text-muted">{{ __('Already planned:') }} {{ $daySpecials->map(fn ($s) => $s->product->name)->join(', ') }}</p>
                                    @endif
                                    <div class="plan-day" data-day="{{ $day }}">
                                        <div class="row plan-row mb-2">
                                            <div class="col-md-5">
                                                <select name="days[{{ $day }}][0][source_product_id]" class="form-control">
                                                    <option value="">{{ __('Select a dish') }}</option>
                                                    @foreach ($dishes as $dish)
                                                        <option value="{{ $dish->id }}">{{ $dish->name }} ({{ $dish->sale_price }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" step="0.0001" min="0" name="days[{{ $day }}][0][quantity_planned]" class="form-control" placeholder="{{ __('Qty planned') }}" value="20">
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" step="0.01" min="0" name="days[{{ $day }}][0][selling_price]" class="form-control" placeholder="{{ __('Price (dish price)') }}">
                                            </div>
                                            <div class="col-md-3">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-plan-row">{{ __('Remove') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary add-plan-row" data-day="{{ $day }}">{{ __('Add a special for this day') }}</button>
                                </div>
                            </div>
                        @endforeach

                        <button type="submit" class="btn btn-primary">{{ __('Save Week Plan') }}</button>
                        <a href="{{ route('kitchen-daily-specials.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    document.querySelectorAll('.add-plan-row').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var day = btn.getAttribute('data-day');
            var container = document.querySelector('.plan-day[data-day="' + day + '"]');
            var index = container.querySelectorAll('.plan-row').length;
            var clone = container.querySelector('.plan-row').cloneNode(true);
            clone.querySelectorAll('input, select').forEach(function (el) {
                el.name = el.name.replace(/days\[[^\]]+\]\[\d+\]/, 'days[' + day + '][' + index + ']');
                if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else if (el.name.indexOf('quantity_planned') !== -1) { el.value = '20'; } else { el.value = ''; }
            });
            container.appendChild(clone);
        });
    });
    document.querySelectorAll('.plan-day').forEach(function (container) {
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-plan-row')) {
                var rows = container.querySelectorAll('.plan-row');
                if (rows.length > 1) { e.target.closest('.plan-row').remove(); }
            }
        });
    });
</script>
@endpush
