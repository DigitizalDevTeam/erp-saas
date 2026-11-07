@extends('layouts.admin')
@section('page-title')
    {{ __('Tag Journal Lines') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('gl-dimensions.index') }}">{{ __('GL Dimensions') }}</a></li>
    <li class="breadcrumb-item">{{ __('Journal') }} #{{ $journal->journal_id }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3"><strong>{{ __('Journal') }}:</strong> #{{ $journal->journal_id }}</div>
                        <div class="col-md-3"><strong>{{ __('Date') }}:</strong> {{ $journal->date }}</div>
                        <div class="col-md-3"><strong>{{ __('Reference') }}:</strong> {{ $journal->reference }}</div>
                        <div class="col-md-3"><strong>{{ __('Description') }}:</strong> {{ $journal->description }}</div>
                    </div>
                    <p class="text-muted">{{ __('Split each line across cost centres. The split must add up to the line amount — a credit line’s dimensions come out negative, so they reconcile to the net journal amount. Saving a line supersedes its old tag; the journal amounts never change.') }}</p>

                    <form method="POST" action="{{ route('gl-dimensions.journal.update', $journal->id) }}" id="glForm">
                        @csrf
                        @foreach ($items as $lineIndex => $item)
                            @php
                                $net = round((float) $item->debit - (float) $item->credit, 2);
                                $abs = abs($net);
                                $dims = $dimensionsByLine->get((int) $item->id, collect());
                                $allFixed = $dims->isNotEmpty() && $dims->every(fn ($d) => $d->percentage === null);
                                $prefillBasis = $allFixed ? 'fixed_amount' : 'percentage';
                                $prefill = [];
                                foreach ($dims as $d) {
                                    $prefill[] = [
                                        'cost_centre_id' => (int) $d->cost_centre_id,
                                        'percentage' => $allFixed ? null : ($d->percentage !== null ? (string) $d->percentage : null),
                                        'fixed_amount' => $allFixed ? (string) $d->allocated_amount : null,
                                    ];
                                }
                                if (empty($prefill)) {
                                    $prefill[] = ['cost_centre_id' => 0, 'percentage' => '', 'fixed_amount' => null];
                                }
                                $account = $item->accounts;
                            @endphp
                            <div class="card mb-3 gl-line" data-line-id="{{ $item->id }}" data-amount="{{ $abs }}">
                                <div class="card-header">
                                    <strong>{{ __('Line') }} #{{ $item->id }}</strong>
                                    — {{ $account ? $account->code.' — '.$account->name : __('Account') }} #{{ $item->account }}
                                    · {{ __('Debit') }} {{ number_format((float) $item->debit, 2) }} · {{ __('Credit') }} {{ number_format((float) $item->credit, 2) }}
                                    · {{ __('Net') }} {{ number_format($net, 2) }}
                                    @if ($item->description) · {{ $item->description }} @endif
                                </div>
                                <div class="card-body">
                                    <input type="hidden" name="lines[{{ $lineIndex }}][line_id]" value="{{ $item->id }}">
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-3">
                                            <label class="form-label">{{ __('Basis') }}</label>
                                            <select class="form-control gl-basis" name="lines[{{ $lineIndex }}][basis]">
                                                <option value="percentage" {{ $prefillBasis === 'percentage' ? 'selected' : '' }}>{{ __('Percentage') }}</option>
                                                <option value="fixed_amount" {{ $prefillBasis === 'fixed_amount' ? 'selected' : '' }}>{{ __('Fixed amount') }}</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">{{ __('Project (optional)') }}</label>
                                            <select class="form-control" name="lines[{{ $lineIndex }}][project_id]">
                                                <option value="">{{ __('No project') }}</option>
                                                @foreach ($projects as $project)
                                                    <option value="{{ $project->id }}">{{ $project->project_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">{{ __('Line amount') }}</label>
                                            <input type="text" class="form-control" value="{{ number_format($abs, 2) }}" readonly>
                                        </div>
                                    </div>

                                    <div class="alert alert-danger d-none gl-errors"></div>

                                    <table class="table mb-2 gl-rows">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Cost centre') }}</th>
                                                <th class="gl-value-header">{{ $prefillBasis === 'fixed_amount' ? __('Fixed amount') : __('Percentage') . ' (%)' }}</th>
                                                <th class="text-end">{{ __('Allocated amount') }}</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($prefill as $i => $row)
                                                <tr>
                                                    <td>
                                                        <select class="form-control gl-centre" name="lines[{{ $lineIndex }}][lines][{{ $i }}][cost_centre_id]">
                                                            <option value="">{{ __('Select cost centre') }}</option>
                                                            @foreach ($centres as $centre)
                                                                <option value="{{ $centre->id }}" {{ (int) $row['cost_centre_id'] === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td><input type="number" step="any" min="0" class="form-control gl-value" name="lines[{{ $lineIndex }}][lines][{{ $i }}][{{ $prefillBasis === 'fixed_amount' ? 'fixed_amount' : 'percentage' }}]" value="{{ $prefillBasis === 'fixed_amount' ? ($row['fixed_amount'] ?? '') : ($row['percentage'] ?? '') }}"></td>
                                                    <td class="text-end gl-allocated">—</td>
                                                    <td><button type="button" class="btn btn-sm btn-outline-danger gl-remove-row">×</button></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <button type="button" class="btn btn-sm btn-outline-primary gl-add-row">{{ __('Add cost centre') }}</button>

                                    <div class="row mt-3">
                                        <div class="col-md-4"><strong>{{ __('Total allocated') }}:</strong> <span class="gl-total-allocated">0.00</span></div>
                                        <div class="col-md-4"><strong>{{ __('Sum') }}:</strong> <span class="gl-live-sum">0%</span></div>
                                        <div class="col-md-4"><strong>{{ __('Rounding difference') }}:</strong> <span class="gl-rounding-difference">0.00</span></div>
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary gl-preview-btn">{{ __('Preview split') }}</button>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save dimensions') }}</button>
                            <a href="{{ route('gl-dimensions.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var centreOptions = @json($centres->map(fn ($c) => ['id' => (int) $c->id, 'label' => $c->code . ' — ' . $c->name])->values());

    document.querySelectorAll('.gl-line').forEach(function (section) {
        var lineId = section.dataset.lineId;
        var amount = parseFloat(section.dataset.amount || '0');
        var tbody = section.querySelector('.gl-rows tbody');
        var basisSelect = section.querySelector('.gl-basis');
        var errorsBox = section.querySelector('.gl-errors');
        var totalEl = section.querySelector('.gl-total-allocated');
        var sumEl = section.querySelector('.gl-live-sum');
        var diffEl = section.querySelector('.gl-rounding-difference');

        function rowIndex() { return tbody.querySelectorAll('tr').length; }

        function refreshHeader() {
            var basis = basisSelect.value;
            section.querySelector('.gl-value-header').textContent = basis === 'fixed_amount' ? 'Fixed amount' : 'Percentage (%)';
            tbody.querySelectorAll('tr').forEach(function (tr) {
                var input = tr.querySelector('.gl-value');
                if (input) input.name = input.name.replace(/\[(percentage|fixed_amount)\]$/, '[' + basis + ']');
            });
        }

        function liveSum() {
            var basis = basisSelect.value;
            var sum = 0;
            tbody.querySelectorAll('.gl-value').forEach(function (input) {
                var v = parseFloat(input.value || '0');
                if (!isNaN(v)) sum += v;
            });
            if (basis === 'percentage') {
                sumEl.textContent = sum.toFixed(6).replace(/\.?0+$/, '') + '%';
                totalEl.textContent = (amount * sum / 100).toFixed(2);
                diffEl.textContent = (amount - amount * sum / 100).toFixed(2);
            } else {
                sumEl.textContent = sum.toFixed(2);
                totalEl.textContent = sum.toFixed(2);
                diffEl.textContent = (amount - sum).toFixed(2);
            }
        }

        basisSelect.addEventListener('change', function () { refreshHeader(); liveSum(); });
        tbody.addEventListener('input', liveSum);
        tbody.addEventListener('click', function (e) {
            if (e.target.classList.contains('gl-remove-row')) {
                var tr = e.target.closest('tr');
                if (tbody.querySelectorAll('tr').length > 1) tr.remove();
                liveSum();
            }
        });

        section.querySelector('.gl-add-row').addEventListener('click', function () {
            var idx = rowIndex();
            var lineIdx = section.querySelector('input[type=hidden]').name.match(/lines\[(\d+)\]/)[1];
            var tr = document.createElement('tr');
            var options = '<option value="">Select cost centre</option>' + centreOptions.map(function (c) {
                return '<option value="' + c.id + '">' + c.label + '</option>';
            }).join('');
            tr.innerHTML = '<td><select class="form-control gl-centre" name="lines[' + lineIdx + '][lines][' + idx + '][cost_centre_id]">' + options + '</select></td>'
                + '<td><input type="number" step="any" min="0" class="form-control gl-value" name="lines[' + lineIdx + '][lines][' + idx + '][' + basisSelect.value + ']" value=""></td>'
                + '<td class="text-end gl-allocated">—</td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-danger gl-remove-row">×</button></td>';
            tbody.appendChild(tr);
        });

        section.querySelector('.gl-preview-btn').addEventListener('click', function () {
            var basis = basisSelect.value;
            var lines = [];
            tbody.querySelectorAll('tr').forEach(function (tr) {
                var centre = tr.querySelector('.gl-centre').value;
                var value = tr.querySelector('.gl-value').value;
                if (!centre || value === '') return;
                var row = { cost_centre_id: parseInt(centre, 10) };
                row[basis] = value;
                lines.push(row);
            });
            errorsBox.classList.add('d-none');
            fetch('{{ route('gl-dimensions.validate') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ line_type: 'journal_item', line_id: parseInt(lineId, 10), basis: basis, lines: lines })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.ok) {
                    errorsBox.textContent = (data.errors || ['Invalid split']).join(' ');
                    errorsBox.classList.remove('d-none');
                    return;
                }
                tbody.querySelectorAll('tr').forEach(function (tr, i) {
                    var cell = tr.querySelector('.gl-allocated');
                    var computed = data.lines[i];
                    cell.textContent = computed ? parseFloat(computed.allocated_amount).toFixed(2) : '—';
                });
                totalEl.textContent = parseFloat(data.total_allocated).toFixed(2);
                diffEl.textContent = parseFloat(data.rounding_difference).toFixed(2);
                sumEl.textContent = basis === 'percentage'
                    ? data.lines.reduce(function (s, r) { return s + parseFloat(r.percentage || 0); }, 0).toFixed(6).replace(/\.?0+$/, '') + '%'
                    : parseFloat(data.total_allocated).toFixed(2);
            });
        });

        liveSum();
    });
});
</script>
@endpush
