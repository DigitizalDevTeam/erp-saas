{{ Form::open(array('route' => 'journal-entry.import', 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="col-md-12 mb-3">
            <p class="text-muted">{{ __('Import journal entries from an XLSX file. One row per journal line; rows with the same reference become one journal entry.') }}</p>
            <a href="{{ route('journal-entry.import.template') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-download"></i> {{ __('Download Template') }}
            </a>
        </div>
        <div class="col-md-12 mb-3">
            <p class="text-muted mb-1"><strong>{{ __('Columns:') }}</strong> journal_number, date, year, month, day, reference, description, account_code, debit, credit, line_description</p>
            <p class="text-muted mb-1">{{ __('Journal number is optional. If given, it must be a whole number not already used; if empty, the next number is assigned automatically.') }}</p>
            <p class="text-muted mb-1">{{ __('If date is empty, year, month and day are used instead, the same fields as on the manual journal entry form.') }}</p>
            <p class="text-muted mb-1">{{ __('If you upload the same file again, entries that are already imported are skipped and counted as already imported.') }}</p>
            <p class="text-muted">{{ __('Only XLSX files are supported.') }}</p>
        </div>
        <div class="col-md-12">
            {{ Form::label('file', __('Select XLSX File'), ['class' => 'form-label']) }}
            <div class="choose-file form-group">
                <label for="file" class="form-label">
                    <input type="file" class="form-control" name="file" id="file" accept=".xlsx" required>
                </label>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Upload') }}" class="btn btn-primary">
</div>
{{ Form::close() }}
