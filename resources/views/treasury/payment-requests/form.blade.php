@extends('layouts.admin')
@section('page-title')
    {{ $paymentRequest ? __('Edit Payment Request') : __('New Payment Request') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-requests.index') }}">{{ __('Payment Requests') }}</a></li>
    <li class="breadcrumb-item">{{ $paymentRequest ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($paymentRequest && in_array($paymentRequest->state, ['under_review', 'awaiting_approval', 'approved'], true))
                        <div class="alert alert-warning">{{ __('Changing the amount, beneficiary, currency, destination account or source account invalidates the approvals and restarts the workflow.') }}</div>
                    @endif
                    <form method="POST" action="{{ $paymentRequest ? route('treasury-payment-requests.update', $paymentRequest) : route('treasury-payment-requests.store') }}">
                        @csrf
                        @if ($paymentRequest)
                            @method('PUT')
                        @endif
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Source account') }} *</label>
                                <select name="source_account_id" class="form-control" required>
                                    <option value="">{{ __('Select an account') }}</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" {{ (string) old('source_account_id', $paymentRequest->source_account_id ?? '') === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->bankAccount->holder_name ?? $account->bankAccount->account_name ?? $account->type }} ({{ $account->currency }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Amount') }} *</label>
                                <input type="number" step="0.0001" min="0.0001" name="amount_original" class="form-control" value="{{ old('amount_original', $paymentRequest->amount_original ?? '') }}" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Currency') }}</label>
                                <select name="currency" class="form-control">
                                    @foreach ($currencies as $currency)
                                        <option value="{{ $currency }}" {{ old('currency', $paymentRequest->currency ?? $accounts->first()->currency ?? 'MZN') === $currency ? 'selected' : '' }}>{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Beneficiary type') }} *</label>
                                <select name="beneficiary_type" class="form-control" required>
                                    @foreach (\App\Models\TreasuryPaymentRequest::BENEFICIARY_TYPES as $type)
                                        <option value="{{ $type }}" {{ old('beneficiary_type', $paymentRequest->beneficiary_type ?? 'vendor') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Beneficiary') }} *</label>
                                <select name="beneficiary_id" class="form-control" required>
                                    <option value="">{{ __('Select a beneficiary') }}</option>
                                    @if ($vendors->isNotEmpty())
                                        <optgroup label="{{ __('Vendors') }}">
                                            @foreach ($vendors as $vendor)
                                                <option value="{{ $vendor->id }}" {{ (string) old('beneficiary_id', $paymentRequest->beneficiary_id ?? '') === (string) $vendor->id && old('beneficiary_type', $paymentRequest->beneficiary_type ?? 'vendor') === 'vendor' ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($employees->isNotEmpty())
                                        <optgroup label="{{ __('Employees') }}">
                                            @foreach ($employees as $employee)
                                                <option value="{{ $employee->id }}" {{ (string) old('beneficiary_id', $paymentRequest->beneficiary_id ?? '') === (string) $employee->id && old('beneficiary_type', $paymentRequest->beneficiary_type ?? '') === 'employee' ? 'selected' : '' }}>{{ $employee->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($customers->isNotEmpty())
                                        <optgroup label="{{ __('Customers') }}">
                                            @foreach ($customers as $customer)
                                                <option value="{{ $customer->id }}" {{ (string) old('beneficiary_id', $paymentRequest->beneficiary_id ?? '') === (string) $customer->id && old('beneficiary_type', $paymentRequest->beneficiary_type ?? '') === 'customer' ? 'selected' : '' }}>{{ $customer->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                                <small class="text-muted">{{ __('Pick the beneficiary from the group that matches the type above.') }}</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Beneficiary account') }}</label>
                                <input type="text" name="beneficiary_account" class="form-control" value="{{ old('beneficiary_account', $paymentRequest->beneficiary_account ?? '') }}" placeholder="{{ __('Bank account / mobile wallet / M-Pesa number') }}">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Due date') }}</label>
                                <input type="date" name="due_date" class="form-control" value="{{ old('due_date', $paymentRequest->due_date ? $paymentRequest->due_date->format('Y-m-d') : '') }}">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Planned date') }}</label>
                                <input type="date" name="planned_date" class="form-control" value="{{ old('planned_date', $paymentRequest->planned_date ? $paymentRequest->planned_date->format('Y-m-d') : '') }}">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Priority') }}</label>
                                <select name="priority" class="form-control">
                                    @foreach (['low', 'normal', 'high', 'urgent'] as $priority)
                                        <option value="{{ $priority }}" {{ old('priority', $paymentRequest->priority ?? 'normal') === $priority ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Client reference') }}</label>
                                <input type="text" name="client_reference" class="form-control" value="{{ old('client_reference', $paymentRequest->client_reference ?? '') }}" placeholder="{{ __('Your own reference — must be unique') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Cost centre') }}</label>
                                <select name="cost_centre_id" class="form-control">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($costCentres as $centre)
                                        <option value="{{ $centre->id }}" {{ (string) old('cost_centre_id', $paymentRequest->cost_centre_id ?? '') === (string) $centre->id ? 'selected' : '' }}>{{ $centre->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Executor (who will run the payment)') }}</label>
                                <select name="executor_user_id" class="form-control">
                                    <option value="">{{ __('None — the requester') }}</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" {{ (string) old('executor_user_id', $paymentRequest->executor_user_id ?? '') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">{{ __('The executor cannot be the only approver of this request.') }}</small>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">{{ __('Reason') }}</label>
                                <textarea name="reason" class="form-control" rows="3">{{ old('reason', $paymentRequest->reason ?? '') }}</textarea>
                            </div>
                            @if ($bills->isNotEmpty())
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">{{ __('Source bills') }}</label>
                                    <div class="row">
                                        @php($selectedDocs = old('source_documents', $paymentRequest ? array_map(fn ($d) => 'bill:' . $d['id'], array_values(array_filter($paymentRequest->sourceDocuments(), fn ($d) => $d['type'] === 'bill'))) : []))
                                        @foreach ($bills as $bill)
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="source_documents[]" value="bill:{{ $bill->id }}" id="bill-{{ $bill->id }}" {{ in_array('bill:' . $bill->id, (array) $selectedDocs, true) ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="bill-{{ $bill->id }}">{{ __('Bill') }} #{{ $bill->bill_id }} ({{ $bill->bill_date ? $bill->bill_date->format('Y-m-d') : '—' }})</label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="text-end">
                            <a href="{{ route('treasury-payment-requests.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <button type="submit" class="btn btn-primary">{{ $paymentRequest ? __('Save changes') : __('Create draft') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
