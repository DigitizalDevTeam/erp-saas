<h5 class="mb-3">{{ __('Year-end closing (SNC-style)') }}</h5>
<p class="text-muted small mb-4">
    {{ __('Closes classes 61–68 and 71–76 to 81, 69/78 to 82, transfers 81+82 to 83, then loss to 88 or IRPC (32%) and net profit to 88. Requires chart accounts whose codes start with 81, 82, 83 (and 85, 44101, 88 for profits). Account classes use the first two digits of each code.') }}
</p>

{{ Form::open(['route' => 'fiscal-year-closing.run', 'method' => 'post', 'class' => 'needs-validation', 'id' => 'fy-closing-form']) }}
@csrf
<div class="row align-items-start">
    <div class="col-md-3">
        <div class="form-group">
            {{ Form::label('fiscal_year', __('Year'), ['class' => 'form-label']) }}
            {{ Form::number('fiscal_year', $defaultYear, [
                'class' => 'form-control',
                'required' => true,
                'min' => 2000,
                'max' => 2100,
                'id' => 'fy_closing_year',
            ]) }}
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Month') }}</label>
            <div class="d-flex flex-wrap gap-3 align-items-center mt-1">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input fy-closing-period" type="radio" name="accounting_period" id="fy_period_13" value="13">
                    <label class="form-check-label" for="fy_period_13">13</label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input fy-closing-period" type="radio" name="accounting_period" id="fy_period_14" value="14" checked>
                    <label class="form-check-label" for="fy_period_14">14</label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input fy-closing-period" type="radio" name="accounting_period" id="fy_period_15" value="15">
                    <label class="form-check-label" for="fy_period_15">15</label>
                </div>
            </div>
            <small class="text-muted d-block mt-1">
                {{ __('13 — Year-end adjustments · 14 — Post-audit · 15 — Final closing') }}
            </small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            {{ Form::label('closing_day', __('Day'), ['class' => 'form-label']) }}
            <input type="number" class="form-control" id="fy_closing_day" value="31" min="31" max="31" readonly>
            <small class="text-muted">{{ __('Always 31 for periods 13–15') }}</small>
        </div>
    </div>
</div>
<input type="hidden" name="closing_date" id="fy_closing_date" value="{{ sprintf('%04d-12-31', (int) $defaultYear) }}">
<div class="form-check mb-3">
    {{ Form::checkbox('allow_duplicate', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate']) }}
    {{ Form::label('allow_duplicate', __('Replace existing closing journals in this year (deletes FY-*-CLOSE* entries dated in the year, then re-posts)'), ['class' => 'form-check-label']) }}
</div>
{{ Form::submit(__('Run fiscal closing'), ['class' => 'btn btn-primary']) }}
{{ Form::close() }}

<script>
(function () {
    function syncFyClosingDate() {
        var year = parseInt(document.getElementById('fy_closing_year')?.value, 10) || new Date().getFullYear();
        var dateInput = document.getElementById('fy_closing_date');
        if (dateInput) {
            dateInput.value = year + '-12-31';
        }
    }
    document.getElementById('fy_closing_year')?.addEventListener('change', syncFyClosingDate);
    document.getElementById('fy_closing_year')?.addEventListener('input', syncFyClosingDate);
    document.getElementById('fy-closing-form')?.addEventListener('submit', syncFyClosingDate);
})();
</script>
