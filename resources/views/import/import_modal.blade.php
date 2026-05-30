<div class="modal-body px-3 py-3">
    <div class="row">
        <div class="col-12">
            <div id="process_area" class="overflow-auto import-data-table">
                <table class="table table-bordered mb-0">
                    <thead class="thead">
                    </thead>
                    <tbody class="tbody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="form-group col-12 d-flex justify-content-end col-form-label gap-2 px-1 pt-3 pb-1">
            <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary csv-import-cancel" data-bs-dismiss="modal">
            <button type="button"
                    data-url="{{ route($route) }}"
                    data-required-count="{{ count($fields ?? []) }}"
                    name="import"
                    id="csv_import_submit"
                    class="btn btn-primary"
                    disabled>{{ __('Import') }}</button>
        </div>
    </div>
</div>
