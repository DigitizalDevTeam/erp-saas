@php
    $voidSettings = \App\Models\Utility::settings();
    $voidStockMode = $voidSettings['document_void_restore_stock'] ?? 'auto';
    $voidAccountingMode = $voidSettings['document_void_credit_note'] ?? 'auto';
    $voidWarehouses = \App\Models\warehouse::where('created_by', \Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
@endphp

<div class="modal fade" id="documentVoidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Void / Cancel Document') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <form method="POST" id="documentVoidForm">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="documentVoidLabel"></p>
                    <p class="small text-warning">{{ __('The document will remain visible in the list with Cancelled status.') }}</p>

                    <div class="form-group mb-3">
                        <label class="form-label" for="void_reason">{{ __('Reason') }} <span class="text-danger">*</span></label>
                        <textarea name="void_reason" id="void_reason" class="form-control" rows="3" maxlength="500" required></textarea>
                    </div>

                    @if($voidStockMode === 'manual')
                        <div class="form-group mb-3">
                            <label class="form-label" for="void_warehouse_id">{{ __('Return stock to warehouse') }} <span class="text-danger">*</span></label>
                            <select name="warehouse_id" id="void_warehouse_id" class="form-control select" required>
                                <option value="">{{ __('Select Warehouse') }}</option>
                                @foreach($voidWarehouses as $wid => $wname)
                                    <option value="{{ $wid }}" {{ (string) ($voidSettings['document_void_default_warehouse_id'] ?? '') === (string) $wid ? 'selected' : '' }}>{{ $wname }}</option>
                                @endforeach
                            </select>
                        </div>
                    @elseif($voidStockMode === 'auto' && $voidWarehouses->isNotEmpty())
                        <div class="form-group mb-3">
                            <label class="form-label" for="void_warehouse_id">{{ __('Return stock to warehouse') }}</label>
                            <select name="warehouse_id" id="void_warehouse_id" class="form-control select">
                                <option value="">{{ __('Default / global stock') }}</option>
                                @foreach($voidWarehouses as $wid => $wname)
                                    <option value="{{ $wid }}" {{ (string) ($voidSettings['document_void_default_warehouse_id'] ?? '') === (string) $wid ? 'selected' : '' }}>{{ $wname }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Optional. Leave empty to restore global product quantity only.') }}</small>
                        </div>
                    @endif

                    @if($voidAccountingMode === 'manual')
                        <div class="form-group mb-0" id="documentVoidNoteWrap">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="create_credit_note" id="create_credit_note" value="1">
                                <label class="form-check-label" for="create_credit_note" id="documentVoidNoteLabel">{{ __('Create credit note') }}</label>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Void Document') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('script-page')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('documentVoidModal');
        if (!modalEl) return;
        var modal = new bootstrap.Modal(modalEl);
        var form = document.getElementById('documentVoidForm');
        var labelEl = document.getElementById('documentVoidLabel');
        var noteLabel = document.getElementById('documentVoidNoteLabel');
        var noteInput = document.getElementById('create_credit_note');

        document.querySelectorAll('.document-void-trigger').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                form.action = btn.getAttribute('data-action');
                labelEl.textContent = btn.getAttribute('data-document-label') || '';
                if (noteLabel && noteInput) {
                    var noteType = btn.getAttribute('data-note-type') || 'credit_note';
                    noteLabel.textContent = noteType === 'debit_note'
                        ? @json(__('Create debit note'))
                        : @json(__('Create credit note'));
                    noteInput.name = noteType === 'debit_note' ? 'create_debit_note' : 'create_credit_note';
                    noteInput.checked = false;
                }
                document.getElementById('void_reason').value = '';
                modal.show();
            });
        });
    });
</script>
@endpush
