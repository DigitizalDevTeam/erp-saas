@extends('layouts.admin')
@section('page-title')
    {{ __('Petty-Cash Fund') }} #{{ $fund->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-petty-cash.index') }}">{{ __('Petty Cash') }}</a></li>
    <li class="breadcrumb-item">{{ __('Fund') }} #{{ $fund->id }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury cash sessions')
            <a href="{{ route('treasury-petty-cash.expense-form', $fund) }}" class="btn btn-sm btn-outline-primary">{{ __('Record Expense') }}</a>
            <a href="{{ route('treasury-petty-cash.edit', $fund) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
            @if ($fund->isActive())
                <form method="POST" action="{{ route('treasury-petty-cash.deactivate', $fund) }}" class="d-inline" onsubmit="return confirm('{{ __('Deactivate this fund?') }}');">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Deactivate') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('treasury-petty-cash.reactivate', $fund) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Reactivate') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">{{ __('Account') }}</dt>
                        <dd class="col-sm-7">{{ $fund->financialAccount ? $fund->financialAccount->displayName() : '—' }} — {{ $fund->financialAccount ? $fund->financialAccount->currency : '' }}</dd>
                        <dt class="col-sm-5">{{ __('Custodian') }}</dt>
                        <dd class="col-sm-7">{{ $fund->custodian ? $fund->custodian->name : '—' }}</dd>
                        <dt class="col-sm-5">{{ __('Status') }}</dt>
                        <dd class="col-sm-7"><span class="badge bg-{{ $fund->isActive() ? 'success' : 'secondary' }}">{{ ucfirst($fund->status) }}</span></dd>
                        <dt class="col-sm-5">{{ __('Balance') }}</dt>
                        <dd class="col-sm-7">{{ number_format((float) $summary['posted_balance'], 2) }}</dd>
                        <dt class="col-sm-5">{{ __('Limit') }}</dt>
                        <dd class="col-sm-7">{{ $summary['limit'] !== null ? number_format((float) $summary['limit'], 2) : '—' }}</dd>
                        <dt class="col-sm-5">{{ __('Replenishment Due') }}</dt>
                        <dd class="col-sm-7">{{ number_format((float) $summary['replenishment_due'], 2) }}</dd>
                        <dt class="col-sm-5">{{ __('Frequency') }}</dt>
                        <dd class="col-sm-7">{{ \App\Models\TreasuryPettyCashFund::FREQUENCY_LABELS[$fund->replenishment_frequency] ?? $fund->replenishment_frequency }}</dd>
                        <dt class="col-sm-5">{{ __('Spent') }}</dt>
                        <dd class="col-sm-7">{{ number_format((float) $summary['spent'], 2) }}</dd>
                        <dt class="col-sm-5">{{ __('Returned') }}</dt>
                        <dd class="col-sm-7">{{ number_format((float) $summary['returned'], 2) }}</dd>
                    </dl>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0">{{ __('Expenses By Status') }}</h6></div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li>{{ __('Pending') }}: {{ $summary['expenses']['pending'] }}</li>
                        <li>{{ __('Approved') }}: {{ $summary['expenses']['approved'] }}</li>
                        <li>{{ __('Executed') }}: {{ $summary['expenses']['executed'] }}</li>
                        <li>{{ __('Rejected') }}: {{ $summary['expenses']['rejected'] }}</li>
                        <li>{{ __('Total') }}: {{ $summary['expenses']['pending'] + $summary['expenses']['approved'] + $summary['expenses']['executed'] + $summary['expenses']['rejected'] }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">{{ __('Expenses') }}</h6></div>
                <div class="card-body">
                    @if ($expenses->isEmpty())
                        <p class="text-muted">{{ __('No expenses on this fund yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>{{ __('Amount') }}</th><th>{{ __('Category') }}</th><th>{{ __('Cost Centre') }}</th><th>{{ __('Status') }}</th><th>{{ __('Returned') }}</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($expenses as $expense)
                                        <tr>
                                            <td>{{ number_format((float) $expense->amount, 2) }}</td>
                                            <td>{{ $expense->category ? $expense->category->name : '—' }}</td>
                                            <td>{{ $expense->costCentre ? $expense->costCentre->name : '—' }}</td>
                                            <td>{{ $expense->status_label }}</td>
                                            <td>{{ number_format((float) $expense->treasury_petty_cash_returned_amount, 2) }}</td>
                                            <td class="text-end">
                                                @can('manage treasury cash sessions')
                                                    @if ((int) $expense->status === \App\Models\Expense::STATUS_PENDING)
                                                        <form method="POST" action="{{ route('treasury-petty-cash.approve-expense', $expense) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Approve') }}</button>
                                                        </form>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $expense->id }}">{{ __('Reject') }}</button>
                                                    @elseif ((int) $expense->status === \App\Models\Expense::STATUS_APPROVED)
                                                        <form method="POST" action="{{ route('treasury-petty-cash.execute-expense', $expense) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Execute') }}</button>
                                                        </form>
                                                    @elseif ((int) $expense->status === \App\Models\Expense::STATUS_EXECUTED)
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#returnModal{{ $expense->id }}">{{ __('Return Unspent') }}</button>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $expenses->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    @foreach ($expenses as $expense)
        @if ((int) $expense->status === \App\Models\Expense::STATUS_PENDING)
            <div class="modal fade" id="rejectModal{{ $expense->id }}" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('treasury-petty-cash.reject-expense', $expense) }}">
                        @csrf
                        <div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title">{{ __('Reject Expense') }}</h5></div>
                            <div class="modal-body">
                                <label class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="3" required maxlength="1000"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                <button type="submit" class="btn btn-danger">{{ __('Reject') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
        @if ((int) $expense->status === \App\Models\Expense::STATUS_EXECUTED)
            <div class="modal fade" id="returnModal{{ $expense->id }}" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('treasury-petty-cash.return-unspent', $expense) }}">
                        @csrf
                        <div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title">{{ __('Return Unspent Amount') }}</h5></div>
                            <div class="modal-body">
                                <p class="text-muted">{{ __('Expense') }} {{ number_format((float) $expense->amount, 2) }} — {{ __('already returned') }} {{ number_format((float) $expense->treasury_petty_cash_returned_amount, 2) }}.</p>
                                <label class="form-label">{{ __('Amount To Return') }} <span class="text-danger">*</span></label>
                                <input type="number" step="0.0001" min="0.0001" name="amount" class="form-control" required>
                                <label class="form-label mt-3">{{ __('Reason') }}</label>
                                <input type="text" name="reason" class="form-control" maxlength="1000">
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                <button type="submit" class="btn btn-primary">{{ __('Return') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
@endsection
