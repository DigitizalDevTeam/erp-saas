@extends('layouts.admin')
@section('page-title')
    {{ $advance ? __('Edit Advance') : __('New Advance') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.index') }}">{{ __('Advances') }}</a></li>
    <li class="breadcrumb-item">{{ $advance ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $advance ? route('treasury-advances.update', $advance) : route('treasury-advances.store') }}">
                        @csrf
                        @if ($advance)
                            @method('PUT')
                        @endif

                        <div class="form-group">
                            <label class="form-label" for="source_account_id">{{ __('Source Account') }} *</label>
                            <select name="source_account_id" id="source_account_id" class="form-control" required>
                                <option value="">{{ __('Select an account') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" {{ (int) old('source_account_id', $advance->source_account_id ?? 0) === (int) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->displayName() }} ({{ $account->currency }})</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('The money is disbursed from this account. Its currency is the advance currency.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="recipient_type">{{ __('Recipient Type') }} *</label>
                            <select name="recipient_type" id="recipient_type" class="form-control" required>
                                <option value="employee" {{ old('recipient_type', $advance->recipient_type ?? 'employee') === 'employee' ? 'selected' : '' }}>{{ __('Employee') }}</option>
                                <option value="supplier" {{ old('recipient_type', $advance->recipient_type ?? '') === 'supplier' ? 'selected' : '' }}>{{ __('Supplier') }}</option>
                            </select>
                        </div>

                        <div class="form-group" id="employee-field">
                            <label class="form-label" for="employee_id">{{ __('Employee') }} *</label>
                            <select name="employee_id" id="employee_id" class="form-control">
                                <option value="">{{ __('Select an employee') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" {{ (int) old('employee_id', $advance && $advance->recipient_type === 'employee' ? $advance->recipient_id : 0) === (int) $employee->id ? 'selected' : '' }}>{{ $employee->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group" id="supplier-field" style="display: none;">
                            <label class="form-label" for="supplier_id">{{ __('Supplier') }} *</label>
                            <select name="supplier_id" id="supplier_id" class="form-control">
                                <option value="">{{ __('Select a supplier') }}</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" {{ (int) old('supplier_id', $advance && $advance->recipient_type === 'supplier' ? $advance->recipient_id : 0) === (int) $supplier->id ? 'selected' : '' }}>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="amount_requested">{{ __('Amount Requested') }} *</label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount_requested" id="amount_requested" class="form-control" value="{{ old('amount_requested', $advance->amount_requested ?? '') }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="due_date">{{ __('Due Date') }}</label>
                                    <input type="date" name="due_date" id="due_date" class="form-control" value="{{ old('due_date', $advance->due_date ?? '') }}">
                                    <small class="text-muted">{{ __('When the money must be substantiated or returned. Past the due date with money outstanding, the advance shows as overdue.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="purpose">{{ __('Purpose') }}</label>
                            <input type="text" name="purpose" id="purpose" class="form-control" maxlength="1000" value="{{ old('purpose', $advance->purpose ?? '') }}" placeholder="{{ __('e.g. Travel to the Beira branch, market purchases') }}">
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $advance ? __('Save Draft') : __('Create Draft') }}</button>
                        <a href="{{ $advance ? route('treasury-advances.show', $advance) : route('treasury-advances.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        (function () {
            var type = document.getElementById('recipient_type');
            var employeeField = document.getElementById('employee-field');
            var supplierField = document.getElementById('supplier-field');
            function toggle() {
                var isEmployee = type.value === 'employee';
                employeeField.style.display = isEmployee ? '' : 'none';
                supplierField.style.display = isEmployee ? 'none' : '';
            }
            type.addEventListener('change', toggle);
            toggle();
        })();
    </script>
@endpush
