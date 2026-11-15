@csrf
<div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
        <input type="date" name="doc_date" value="{{ old('doc_date', isset($doc) ? $doc->doc_date?->format('Y-m-d') : date('Y-m-d')) }}" class="form-control @error('doc_date') is-invalid @enderror" required>
        @error('doc_date')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">{{ __('Type') }} <span class="text-danger">*</span></label>
        <select name="doc_type" class="form-control @error('doc_type') is-invalid @enderror" required>
            @foreach (\App\Models\ManagementDocument::DOC_TYPES as $t)
                <option value="{{ $t }}" {{ old('doc_type', $doc->doc_type ?? 'invoice') === $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
        @error('doc_type')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
        <small class="text-muted">{{ __('A credit note must have a negative amount. A quotation is stored but never counts as a cost.') }}</small>
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">{{ __('Status') }} <span class="text-danger">*</span></label>
        <select name="status" class="form-control @error('status') is-invalid @enderror" required>
            @foreach (\App\Models\ManagementDocument::STATUSES as $s)
                <option value="{{ $s }}" {{ old('status', $doc->status ?? 'Conciliado') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        @error('status')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Supplier') }}</label>
        <input type="text" name="supplier_name" value="{{ old('supplier_name', $doc->supplier_name ?? '') }}" class="form-control @error('supplier_name') is-invalid @enderror" placeholder="{{ __('Supplier name') }}">
        @error('supplier_name')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Supplier NIF') }}</label>
        <input type="text" name="supplier_nif" value="{{ old('supplier_nif', $doc->supplier_nif ?? '') }}" class="form-control @error('supplier_nif') is-invalid @enderror">
        @error('supplier_nif')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Document No.') }}</label>
        <input type="text" name="doc_number" value="{{ old('doc_number', $doc->doc_number ?? '') }}" class="form-control @error('doc_number') is-invalid @enderror">
        @error('doc_number')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
        <small class="text-muted">{{ __('Required for every type except a manual entry.') }}</small>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Category') }} <span class="text-danger">*</span></label>
        <select name="management_rule_id" class="form-control @error('management_rule_id') is-invalid @enderror">
            <option value="">{{ __('Use the supplier\'s default category') }}</option>
            @foreach ($rules as $rule)
                <option value="{{ $rule->id }}" {{ (string) old('management_rule_id', $doc->management_rule_id ?? '') === (string) $rule->id ? 'selected' : '' }}>{{ $rule->label() }}</option>
            @endforeach
        </select>
        @error('management_rule_id')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Cost Centre') }}</label>
        <select name="management_cost_centre_id" class="form-control @error('management_cost_centre_id') is-invalid @enderror">
            <option value="">{{ __('No cost centre') }}</option>
            @foreach ($centres as $centre)
                <option value="{{ $centre->id }}" {{ (string) old('management_cost_centre_id', $doc->management_cost_centre_id ?? '') === (string) $centre->id ? 'selected' : '' }}>{{ $centre->label() }}</option>
            @endforeach
        </select>
        @error('management_cost_centre_id')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label class="form-label">{{ __('Base') }}</label>
        <input type="number" step="0.01" min="0" name="base" value="{{ old('base', $doc->base ?? '') }}" class="form-control @error('base') is-invalid @enderror">
        @error('base')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label class="form-label">{{ __('VAT') }}</label>
        <input type="number" step="0.01" min="0" name="iva" value="{{ old('iva', $doc->iva ?? '') }}" class="form-control @error('iva') is-invalid @enderror">
        @error('iva')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label class="form-label">{{ __('Retention') }}</label>
        <input type="number" step="0.01" min="0" name="retention" value="{{ old('retention', $doc->retention ?? '') }}" class="form-control @error('retention') is-invalid @enderror">
        @error('retention')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label class="form-label">{{ __('Total') }} <span class="text-danger">*</span></label>
        <input type="number" step="0.01" name="total" value="{{ old('total', $doc->total ?? '') }}" class="form-control @error('total') is-invalid @enderror" required>
        @error('total')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
        <small class="text-muted">{{ __('Total must equal base + VAT − retention when base is given.') }}</small>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Bank Account') }}</label>
        <input type="text" name="bank_account" value="{{ old('bank_account', $doc->bank_account ?? '') }}" class="form-control @error('bank_account') is-invalid @enderror">
        @error('bank_account')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('Payment Method') }}</label>
        <input type="text" name="payment_method" value="{{ old('payment_method', $doc->payment_method ?? '') }}" class="form-control @error('payment_method') is-invalid @enderror">
        @error('payment_method')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
    <div class="col-md-12 mb-3">
        <label class="form-label">{{ __('Notes') }}</label>
        <textarea name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $doc->notes ?? '') }}</textarea>
        @error('notes')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
    </div>
</div>
<div class="text-end">
    <a href="{{ route('management-documents.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
</div>
