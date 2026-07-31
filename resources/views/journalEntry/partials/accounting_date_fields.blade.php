@php
    use App\Support\AccountingPeriodDate;

    $parsed = AccountingPeriodDate::parse($dateValue ?? null, $periodValue ?? null);
    $monthOptions = AccountingPeriodDate::monthOptions();
    $year = (int) ($parsed['year']);
    $month = (int) ($parsed['month']);
    $day = (int) ($parsed['day']);
    $maxDay = AccountingPeriodDate::daysInPeriod($year, $month);
@endphp
<div class="col-lg-2 col-md-4">
    <div class="form-group">
        {{ Form::label('journal_year', __('Year'), ['class' => 'form-label']) }}<x-required></x-required>
        {{ Form::number('journal_year', $year, [
            'class' => 'form-control journal-year',
            'min' => 2000,
            'max' => 2100,
            'required' => 'required',
            'id' => 'journal_year',
        ]) }}
    </div>
</div>
<div class="col-lg-3 col-md-4">
    <div class="form-group">
        {{ Form::label('journal_month', __('Month'), ['class' => 'form-label']) }}<x-required></x-required>
        {{ Form::select('journal_month', $monthOptions, $month, [
            'class' => 'form-control journal-month',
            'required' => 'required',
            'id' => 'journal_month',
        ]) }}
        <small class="text-muted">{{ __('0–15 (opening / months / closing)') }}</small>
    </div>
</div>
<div class="col-lg-2 col-md-4">
    <div class="form-group">
        {{ Form::label('journal_day', __('Day'), ['class' => 'form-label']) }}<x-required></x-required>
        <select name="journal_day" id="journal_day" class="form-control journal-day" required>
            @for ($d = 1; $d <= $maxDay; $d++)
                <option value="{{ $d }}" {{ (int) $d === (int) $day ? 'selected' : '' }}>{{ $d }}</option>
            @endfor
        </select>
    </div>
</div>
<input type="hidden" name="date" id="journal_date_hidden" value="{{ AccountingPeriodDate::composeDate($year, $month, $day) }}">
<input type="hidden" name="accounting_period" id="journal_period_hidden" value="{{ $month }}">
<div class="col-lg-5 col-md-12">
    <div class="form-group">
        <label class="form-label">{{ __('Selected') }}</label>
        <div class="form-control-plaintext fw-semibold" id="journal_date_preview">
            {{ sprintf('%02d-%02d / %04d', $day, $month, $year) }}
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__journalAccountingDateBound) {
        return;
    }
    window.__journalAccountingDateBound = true;

    function daysInPeriod(year, month) {
        month = parseInt(month, 10);
        year = parseInt(year, 10) || new Date().getFullYear();
        if (month === 0 || month >= 13) {
            return 31;
        }
        return new Date(year, month, 0).getDate();
    }

    function composeDate(year, month, day) {
        year = parseInt(year, 10);
        month = parseInt(month, 10);
        day = parseInt(day, 10);
        var max = daysInPeriod(year, month);
        if (day > max) day = max;
        if (day < 1) day = 1;

        var calMonth = month;
        if (month === 0) calMonth = 1;
        else if (month >= 13) calMonth = 12;

        var mm = String(calMonth).padStart(2, '0');
        var dd = String(day).padStart(2, '0');
        return year + '-' + mm + '-' + dd;
    }

    function refreshJournalDayOptions() {
        var $year = $('#journal_year');
        var $month = $('#journal_month');
        var $day = $('#journal_day');
        if (!$year.length || !$month.length || !$day.length) return;

        var year = parseInt($year.val(), 10) || new Date().getFullYear();
        var month = parseInt($month.val(), 10);
        if (isNaN(month)) month = 1;
        var currentDay = parseInt($day.val(), 10) || 1;
        var max = daysInPeriod(year, month);
        if (currentDay > max) currentDay = max;

        var html = '';
        for (var d = 1; d <= max; d++) {
            html += '<option value="' + d + '"' + (d === currentDay ? ' selected' : '') + '>' + d + '</option>';
        }
        $day.html(html);

        $('#journal_date_hidden').val(composeDate(year, month, currentDay));
        $('#journal_period_hidden').val(month);
        $('#journal_date_preview').text(
            String(currentDay).padStart(2, '0') + '-' + String(month).padStart(2, '0') + ' / ' + year
        );
    }

    $(document).on('change input', '#journal_year, #journal_month, #journal_day', refreshJournalDayOptions);
    $(function () { refreshJournalDayOptions(); });
})();
</script>
