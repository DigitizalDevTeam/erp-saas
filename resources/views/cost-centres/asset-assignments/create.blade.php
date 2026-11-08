@extends('layouts.admin')
@section('page-title')
    {{ __('New Asset Assignment') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('asset-cost-centre-assignments.index') }}">{{ __('Asset Assignments') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Assignment') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Assign an asset to one or more cost centres from a start date. The split must add up to exactly 100%. The new assignment supersedes any open one from the day before the start date — history is kept.') }}</p>

                    <form method="POST" action="{{ route('asset-cost-centre-assignments.store') }}">
                        @csrf
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Asset') }}</label>
                                <select name="asset_id" class="form-control" required>
                                    <option value="">{{ __('Select an asset') }}</option>
                                    @foreach ($assets as $asset)
                                        <option value="{{ $asset->id }}" {{ (string) old('asset_id', $selectedAsset?->id) === (string) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }} ({{ ucfirst(str_replace('_', ' ', $asset->status)) }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Valid from') }}</label>
                                <input type="date" name="valid_from" class="form-control" value="{{ old('valid_from', now()->format('Y-m-d')) }}" required>
                            </div>
                        </div>

                        <h6>{{ __('Split') }} — <span id="split-total">0.00</span>% {{ __('of 100%') }}</h6>
                        <div id="split-rows">
                            <div class="row g-2 mb-2 split-row">
                                <div class="col-md-6">
                                    <select name="splits[0][cost_centre_id]" class="form-control" required>
                                        <option value="">{{ __('Select a cost centre') }}</option>
                                        @foreach ($centres as $centre)
                                            <option value="{{ $centre->id }}">{{ $centre->code }} — {{ $centre->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" name="splits[0][percentage]" class="form-control split-percentage" step="0.000001" min="0.000001" max="100" placeholder="100.000000" required>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-outline-danger remove-split-row">{{ __('Remove') }}</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="add-split-row" class="btn btn-outline-secondary mb-3">{{ __('Add centre') }}</button>

                        <div>
                            <button type="submit" class="btn btn-primary">{{ __('Save Assignment') }}</button>
                            <a href="{{ route('asset-cost-centre-assignments.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    (function () {
        var container = document.getElementById('split-rows');
        var totalEl = document.getElementById('split-total');
        var index = 1;

        function updateTotal() {
            var total = 0;
            container.querySelectorAll('.split-percentage').forEach(function (input) {
                total += parseFloat(input.value) || 0;
            });
            totalEl.textContent = total.toFixed(2);
            totalEl.style.color = Math.abs(total - 100) < 0.000001 ? 'green' : 'red';
        }

        container.addEventListener('input', updateTotal);
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-split-row')) {
                var rows = container.querySelectorAll('.split-row');
                if (rows.length > 1) {
                    e.target.closest('.split-row').remove();
                    updateTotal();
                }
            }
        });

        document.getElementById('add-split-row').addEventListener('click', function () {
            var first = container.querySelector('.split-row');
            var clone = first.cloneNode(true);
            clone.querySelectorAll('select, input').forEach(function (el) {
                el.name = el.name.replace(/splits\[\d+\]/, 'splits[' + index + ']');
                if (el.tagName === 'INPUT') el.value = '';
                if (el.tagName === 'SELECT') el.selectedIndex = 0;
            });
            index++;
            container.appendChild(clone);
        });

        updateTotal();
    })();
</script>
@endpush
