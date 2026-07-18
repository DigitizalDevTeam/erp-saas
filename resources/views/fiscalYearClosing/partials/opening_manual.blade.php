<h5 class="mb-3">{{ __('Manual opening balances') }}</h5>
<p class="text-muted small">
    {{ __('Enter or adjust opening balances line by line for classes 1–5. You can prefill from the automatic carry-forward preview and edit amounts before posting.') }}
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm"
       href="{{ route('fiscal-year-closing.index', ['tab' => 'opening', 'opening_mode' => 'manual', 'opening_target_year' => $openingTargetYear, 'prefill_auto' => 1]) }}">
        {{ __('Prefill from automatic carry-forward') }}
    </a>
    @can('create journal entry')
        <a class="btn btn-outline-secondary btn-sm"
           href="{{ route('journal-entry.create', ['accounting_period' => 0, 'date' => sprintf('%04d-01-01', $openingTargetYear)]) }}">
            {{ __('Create via Journal Entry (approval workflow)') }}
        </a>
    @endcan
</div>

{{ Form::open(['route' => 'fiscal-year-closing.opening.manual', 'method' => 'post', 'id' => 'manual-opening-form']) }}
@csrf
<div class="row mb-3">
    <div class="col-md-4">
        <div class="form-group mb-0">
            {{ Form::label('opening_target_year', __('Target fiscal year'), ['class' => 'form-label']) }}
            {{ Form::number('opening_target_year', $openingTargetYear, ['class' => 'form-control', 'required' => true, 'min' => 2001, 'max' => 2100]) }}
        </div>
    </div>
</div>

<div class="table-responsive mb-3">
    <table class="table table-sm" id="manual-opening-lines">
        <thead>
            <tr>
                <th style="width: 45%">{{ __('Account (classes 1–5)') }}</th>
                <th class="text-end">{{ __('Debit') }}</th>
                <th class="text-end">{{ __('Credit') }}</th>
                <th style="width: 5%"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($manualOpeningLines as $index => $line)
                <tr class="manual-opening-row">
                    <td>
                        <select name="lines[{{ $index }}][account_id]" class="form-control form-control-sm">
                            <option value="">{{ __('Select account') }}</option>
                            @foreach ($openingAccounts as $account)
                                <option value="{{ $account['id'] }}" @selected((string) $account['id'] === (string) ($line['account_id'] ?? ''))>
                                    {{ $account['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="lines[{{ $index }}][debit]"
                               value="{{ $line['debit'] ?? '' }}" class="form-control form-control-sm text-end manual-debit">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="lines[{{ $index }}][credit]"
                               value="{{ $line['credit'] ?? '' }}" class="form-control form-control-sm text-end manual-credit">
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-danger manual-remove-row" @if ($loop->first && count($manualOpeningLines) === 1) disabled @endif>&times;</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="fw-bold">
                <td>{{ __('Totals') }}</td>
                <td class="text-end"><span id="manual-total-debit">0.00</span></td>
                <td class="text-end"><span id="manual-total-credit">0.00</span></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<button type="button" class="btn btn-outline-primary btn-sm mb-3" id="manual-add-row">{{ __('Add line') }}</button>

<div class="form-check mb-3">
    {{ Form::checkbox('allow_duplicate_manual_opening', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate_manual_opening']) }}
    {{ Form::label('allow_duplicate_manual_opening', __('Allow posting even if manual opening reference already exists (not recommended)'), ['class' => 'form-check-label']) }}
</div>

{{ Form::submit(__('Post manual opening balances'), ['class' => 'btn btn-primary']) }}
{{ Form::close() }}

<template id="manual-opening-row-template">
    <tr class="manual-opening-row">
        <td>
            <select name="lines[__INDEX__][account_id]" class="form-control form-control-sm">
                <option value="">{{ __('Select account') }}</option>
                @foreach ($openingAccounts as $account)
                    <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                @endforeach
            </select>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="lines[__INDEX__][debit]" class="form-control form-control-sm text-end manual-debit">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="lines[__INDEX__][credit]" class="form-control form-control-sm text-end manual-credit">
        </td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-danger manual-remove-row">&times;</button>
        </td>
    </tr>
</template>

@push('script-page')
    <script>
        (function () {
            var tableBody = document.querySelector('#manual-opening-lines tbody');
            var rowTemplate = document.getElementById('manual-opening-row-template');
            if (!tableBody || !rowTemplate) {
                return;
            }

            function nextIndex() {
                return tableBody.querySelectorAll('.manual-opening-row').length;
            }

            function recalcTotals() {
                var debitTotal = 0;
                var creditTotal = 0;
                tableBody.querySelectorAll('.manual-debit').forEach(function (input) {
                    debitTotal += parseFloat(input.value || 0);
                });
                tableBody.querySelectorAll('.manual-credit').forEach(function (input) {
                    creditTotal += parseFloat(input.value || 0);
                });
                document.getElementById('manual-total-debit').textContent = debitTotal.toFixed(2);
                document.getElementById('manual-total-credit').textContent = creditTotal.toFixed(2);
            }

            function bindRow(row) {
                row.querySelectorAll('.manual-debit, .manual-credit').forEach(function (input) {
                    input.addEventListener('input', recalcTotals);
                });

                var removeButton = row.querySelector('.manual-remove-row');
                if (removeButton) {
                    removeButton.addEventListener('click', function () {
                        if (tableBody.querySelectorAll('.manual-opening-row').length <= 1) {
                            return;
                        }
                        row.remove();
                        recalcTotals();
                    });
                }
            }

            tableBody.querySelectorAll('.manual-opening-row').forEach(bindRow);
            recalcTotals();

            document.getElementById('manual-add-row').addEventListener('click', function () {
                var html = rowTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex()));
                tableBody.insertAdjacentHTML('beforeend', html);
                bindRow(tableBody.lastElementChild);
                recalcTotals();
            });
        })();
    </script>
@endpush
