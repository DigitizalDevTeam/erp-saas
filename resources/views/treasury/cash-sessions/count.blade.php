@extends('layouts.admin')
@section('page-title')
    {{ __('Count Cash') }} — {{ __('Session') }} #{{ $session->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.index') }}">{{ __('Cash Registers') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.show', $session) }}">{{ __('Session') }} #{{ $session->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Count') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">{{ __('Expected cash:') }} <strong>{{ number_format((float) $session->expected_balance, 2) }} {{ $session->currency }}</strong> {{ __('(opening float + cash inflows − cash outflows). Count the physical cash by denomination — the variance is computed from this count.') }}</p>
                    <form method="POST" action="{{ route('treasury-cash-sessions.record-count', $session) }}">
                        @csrf
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>{{ __('Denomination') }}</th><th>{{ __('Count') }}</th><th>{{ __('Amount') }}</th></tr>
                                </thead>
                                <tbody>
                                    @php $existing = $session->counts->keyBy(function ($c) { return number_format((float) $c->denomination, 4, '.', ''); }); @endphp
                                    @foreach (\App\Models\TreasuryCashSession::DENOMINATIONS as $i => $denomination)
                                        <tr>
                                            <td>{{ number_format((float) $denomination, 2) }} {{ $session->currency }}</td>
                                            <td>
                                                <input type="hidden" name="counts[{{ $i }}][denomination]" value="{{ $denomination }}">
                                                <input type="number" min="0" step="1" name="counts[{{ $i }}][count]" class="form-control count-input" data-denomination="{{ $denomination }}" value="{{ old('counts.' . $i . '.count', $existing[number_format((float) $denomination, 4, '.', '')]->count ?? 0) }}">
                                            </td>
                                            <td><span class="row-amount">0.00</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="2">{{ __('Counted Total') }}</th>
                                        <th><span id="counted_total">0.00</span> {{ $session->currency }}</th>
                                    </tr>
                                    <tr>
                                        <th colspan="2">{{ __('Variance (counted − expected)') }}</th>
                                        <th><span id="variance_total">0.00</span> {{ $session->currency }}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Record Count') }}</button>
                        <a href="{{ route('treasury-cash-sessions.show', $session) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        (function () {
            var expected = parseFloat('{{ (float) $session->expected_balance }}');
            var inputs = document.querySelectorAll('.count-input');

            function refresh() {
                var total = 0;
                inputs.forEach(function (input) {
                    var denomination = parseFloat(input.getAttribute('data-denomination') || '0');
                    var count = parseInt(input.value || '0', 10);
                    var amount = denomination * count;
                    total += amount;
                    var rowAmount = input.closest('tr').querySelector('.row-amount');
                    if (rowAmount) {
                        rowAmount.textContent = amount.toFixed(2);
                    }
                });
                document.getElementById('counted_total').textContent = total.toFixed(2);
                document.getElementById('variance_total').textContent = (total - expected).toFixed(2);
            }

            inputs.forEach(function (input) {
                input.addEventListener('input', refresh);
            });
            refresh();
        })();
    </script>
@endpush
