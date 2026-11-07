@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Advance') }} {{ $advance->operation->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.index') }}">{{ __('Advances') }}</a></li>
    <li class="breadcrumb-item">{{ $advance->operation->reference ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury advances')
            @if ($advance->isDraft())
                <a href="{{ route('treasury-advances.edit', $advance) }}" class="btn btn-sm btn-outline-primary">{{ __('Edit Draft') }}</a>
                <form method="POST" action="{{ route('treasury-advances.submit', $advance) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Submit for Approval') }}</button>
                </form>
                <form method="POST" action="{{ route('treasury-advances.cancel', $advance) }}" class="d-inline" onsubmit="return confirm('{{ __('Cancel this advance draft?') }}')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Cancel') }}</button>
                </form>
            @elseif ($advance->isPendingApproval())
                <form method="POST" action="{{ route('treasury-advances.approve', $advance) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Approve') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">{{ __('Reject') }}</button>
                <form method="POST" action="{{ route('treasury-advances.return-to-draft', $advance) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Return to Draft') }}</button>
                </form>
            @elseif ($advance->isApproved())
                <a href="{{ route('treasury-advances.disburse-form', $advance) }}" class="btn btn-sm btn-primary">{{ __('Disburse') }}</a>
            @elseif ($advance->isRejected())
                <form method="POST" action="{{ route('treasury-advances.return-to-draft', $advance) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Return to Draft — Fix and Resubmit') }}</button>
                </form>
            @elseif ($advance->isDisbursed())
                <a href="{{ route('treasury-advances.return-form', $advance) }}" class="btn btn-sm btn-outline-primary">{{ __('Return Unspent Money') }}</a>
            @elseif ($advance->isRegularised() && ! $advance->hasOpenReimbursement())
                <form method="POST" action="{{ route('treasury-advances.close', $advance) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Close Advance') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('Reference') }}</dt>
                        <dd class="col-sm-8">{{ $advance->operation->reference ?? __('Draft — reference is allocated on disbursement') }}</dd>
                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-{{ in_array($advance->state, ['rejected', 'cancelled']) ? 'danger' : ($advance->state === 'regularised' ? 'success' : ($advance->state === 'closed' ? 'dark' : ($advance->state === 'disbursed' ? 'primary' : ($advance->state === 'pending_approval' || $advance->state === 'under_review' ? 'warning' : 'secondary')))) }}">{{ ucfirst(str_replace('_', ' ', $advance->state)) }}</span>
                            @if ($advance->isOverdue())
                                <span class="badge bg-danger">{{ __('Overdue') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Recipient') }}</dt>
                        <dd class="col-sm-8">{{ $advance->recipientLabel() }}</dd>
                        <dt class="col-sm-4">{{ __('Purpose') }}</dt>
                        <dd class="col-sm-8">{{ $advance->purpose ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Source Account') }}</dt>
                        <dd class="col-sm-8">{{ $advance->sourceAccount ? $advance->sourceAccount->code . ' — ' . $advance->sourceAccount->displayName() : '—' }} ({{ $advance->currency }})</dd>
                        <dt class="col-sm-4">{{ __('Due Date') }}</dt>
                        <dd class="col-sm-8">{{ $advance->due_date ?? '—' }}</dd>
                        @if ($advance->rejection_reason)
                            <dt class="col-sm-4">{{ __('Rejection Reason') }}</dt>
                            <dd class="col-sm-8">{{ $advance->rejection_reason }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Amounts') }}</h5></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('Requested') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_requested, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Approved') }}</dt>
                        <dd class="col-sm-8">{{ $advance->amount_approved !== null ? number_format((float) $advance->amount_approved, 2) . ' ' . $advance->currency : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Paid') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_paid, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Substantiated') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_substantiated, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Returned') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_returned, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Outstanding') }}</dt>
                        <dd class="col-sm-8"><strong>{{ number_format((float) $advance->amount_outstanding, 2) }} {{ $advance->currency }}</strong></dd>
                        @if (bccomp((string) $advance->reimbursement_amount, '0', 4) > 0)
                            <dt class="col-sm-4">{{ __('Reimbursement') }}</dt>
                            <dd class="col-sm-8">
                                {{ number_format((float) $advance->reimbursement_amount, 2) }} {{ $advance->currency }}
                                <span class="badge bg-{{ $advance->reimbursement_state === 'paid' ? 'success' : ($advance->reimbursement_state === 'approved' ? 'primary' : ($advance->reimbursement_state === 'rejected' ? 'danger' : 'warning')) }}">{{ ucfirst($advance->reimbursement_state) }}</span>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($advance->reimbursement_state === 'pending' || $advance->reimbursement_state === 'approved')
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ __('Reimbursement') }}</h5></div>
                    <div class="card-body">
                        <p>{{ __('Approved expenses exceed the advance by :amount. That excess is a separate reimbursement — it is not part of the advance itself.', ['amount' => number_format((float) $advance->reimbursement_amount, 2) . ' ' . $advance->currency]) }}</p>
                        @can('manage treasury advances')
                            @if ($advance->reimbursement_state === 'pending')
                                <form method="POST" action="{{ route('treasury-advances.approve-reimbursement', $advance) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Approve Reimbursement') }}</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectReimbursementModal">{{ __('Reject Reimbursement') }}</button>
                            @elseif ($advance->reimbursement_state === 'approved')
                                <form method="POST" action="{{ route('treasury-advances.pay-reimbursement', $advance) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Pay Reimbursement') }}</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Expense Lines') }}</h5></div>
                <div class="card-body">
                    @if ($advance->lines->isEmpty())
                        <p class="text-muted">{{ __('No expense lines yet. After disbursement, add one line per expense with its document and evidence.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Date') }}</th>
                                        <th>{{ __('Counterparty') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Expense Account') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Review') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($advance->lines as $line)
                                        <tr>
                                            <td>{{ $line->expense_date }}</td>
                                            <td>{{ $line->counterparty ?? '—' }}@if ($line->document)<br><small class="text-muted">{{ $line->document }}</small>@endif</td>
                                            <td>{{ number_format((float) $line->amount, 2) }} {{ $advance->currency }}</td>
                                            <td>{{ $line->chartAccount ? $line->chartAccount->code . ' — ' . $line->chartAccount->name : '—' }}@if ($line->costCentre)<br><small class="text-muted">{{ $line->costCentre->code }} — {{ $line->costCentre->name }}</small>@endif</td>
                                            <td>
                                                <span class="badge bg-{{ $line->state === 'approved' ? 'success' : ($line->state === 'rejected' ? 'danger' : ($line->state === 'returned' ? 'warning' : 'secondary')) }}">{{ ucfirst($line->state) }}</span>
                                                @if ($line->review_reason)<br><small class="text-muted">{{ $line->review_reason }}</small>@endif
                                            </td>
                                            <td>
                                                @can('manage treasury advances')
                                                    @if ($line->isPending())
                                                        <form method="POST" action="{{ route('treasury-advances.review-line', $line) }}">
                                                            @csrf
                                                            <div class="input-group input-group-sm mb-1">
                                                                <input type="text" name="reason" class="form-control" placeholder="{{ __('Reason (required to reject or return)') }}" maxlength="1000">
                                                            </div>
                                                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                                                            <button type="submit" name="decision" value="return" class="btn btn-sm btn-warning">{{ __('Return') }}</button>
                                                            <button type="submit" name="decision" value="reject" class="btn btn-sm btn-danger">{{ __('Reject') }}</button>
                                                        </form>
                                                    @elseif ($line->isReturned())
                                                        <a href="{{ route('treasury-advances.line-form', $line) }}" class="btn btn-sm btn-outline-primary">{{ __('Edit') }}</a>
                                                        <form method="POST" action="{{ route('treasury-advances.resubmit-line', $line) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Resubmit') }}</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @can('manage treasury advances')
                        @if ($advance->isDisbursed())
                            <hr>
                            <h6>{{ __('Add Expense Line') }}</h6>
                            <form method="POST" action="{{ route('treasury-advances.add-line', $advance) }}">
                                @csrf
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="form-label" for="expense_date">{{ __('Expense Date') }} *</label>
                                            <input type="date" name="expense_date" id="expense_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="form-label" for="counterparty">{{ __('Counterparty') }}</label>
                                            <input type="text" name="counterparty" id="counterparty" class="form-control" maxlength="150">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="form-label" for="document">{{ __('Document') }}</label>
                                            <input type="text" name="document" id="document" class="form-control" maxlength="150" placeholder="{{ __('Invoice / receipt no.') }}">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="form-label" for="amount">{{ __('Amount') }} *</label>
                                            <input type="number" step="0.0001" min="0.0001" name="amount" id="amount" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label" for="chart_account_id">{{ __('Expense Account') }} *</label>
                                            <select name="chart_account_id" id="chart_account_id" class="form-control" required>
                                                <option value="">{{ __('Select an expense account') }}</option>
                                                @foreach ($chartAccounts as $chart)
                                                    <option value="{{ $chart->id }}">{{ $chart->code }} — {{ $chart->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                                            <select name="cost_centre_id" id="cost_centre_id" class="form-control">
                                                <option value="">{{ __('None') }}</option>
                                                @foreach ($costCentres as $centre)
                                                    <option value="{{ $centre->id }}">{{ $centre->code }} — {{ $centre->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="evidence">{{ __('Evidence') }}</label>
                                    <textarea name="evidence" id="evidence" class="form-control" rows="2" maxlength="1000" placeholder="{{ __('Receipt details, note, or attachment reference') }}"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">{{ __('Submit Line') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @can('manage treasury advances')
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('treasury-advances.reject', $advance) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">{{ __('Reject Advance') }}</h5></div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label" for="reject_reason">{{ __('Reason') }} *</label>
                                <textarea name="reason" id="reject_reason" class="form-control" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger">{{ __('Reject') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="rejectReimbursementModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('treasury-advances.reject-reimbursement', $advance) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">{{ __('Reject Reimbursement') }}</h5></div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label" for="reject_reimbursement_reason">{{ __('Reason') }} *</label>
                                <textarea name="reason" id="reject_reimbursement_reason" class="form-control" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger">{{ __('Reject') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
