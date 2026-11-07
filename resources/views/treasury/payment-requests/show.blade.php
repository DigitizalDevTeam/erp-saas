@extends('layouts.admin')
@section('page-title')
    {{ __('Payment Request') }} {{ $paymentRequest->operation->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-requests.index') }}">{{ __('Payment Requests') }}</a></li>
    <li class="breadcrumb-item">{{ $paymentRequest->operation->reference ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create treasury payment requests')
            @if (in_array($paymentRequest->state, ['draft', 'under_review', 'awaiting_approval', 'approved'], true))
                <a href="{{ route('treasury-payment-requests.edit', $paymentRequest) }}" class="btn btn-sm btn-outline-primary">{{ __('Edit') }}</a>
            @endif
            @if ($paymentRequest->isDraft())
                <form method="POST" action="{{ route('treasury-payment-requests.submit', $paymentRequest) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Submit') }}</button>
                </form>
            @endif
            @if ($paymentRequest->isUnderReview())
                <form method="POST" action="{{ route('treasury-payment-requests.send-for-approval', $paymentRequest) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Send for approval') }}</button>
                </form>
            @endif
            @if (in_array($paymentRequest->state, ['under_review', 'awaiting_approval'], true))
                <form method="POST" action="{{ route('treasury-payment-requests.reject', $paymentRequest) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Reject this payment request?') }}')">{{ __('Reject') }}</button>
                </form>
                <form method="POST" action="{{ route('treasury-payment-requests.return-to-draft', $paymentRequest) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Return to draft') }}</button>
                </form>
            @endif
            @if (in_array($paymentRequest->state, ['draft', 'under_review', 'awaiting_approval'], true))
                <form method="POST" action="{{ route('treasury-payment-requests.cancel', $paymentRequest) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('{{ __('Cancel this payment request?') }}')">{{ __('Cancel') }}</button>
                </form>
            @endif
            @if ($paymentRequest->isDraft())
                <form method="POST" action="{{ route('treasury-payment-requests.destroy', $paymentRequest) }}" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Delete this draft?') }}')">{{ __('Delete') }}</button>
                </form>
            @endif
        @endcan
        @can('execute treasury payments')
            @if (in_array($paymentRequest->state, ['approved', 'processing', 'outcome_unknown'], true))
                <a href="{{ route('treasury-payment-requests.execute-form', $paymentRequest) }}" class="btn btn-sm btn-primary">{{ __('Execute payment') }}</a>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            @if ($paymentRequest->similar_flags)
                <div class="alert alert-warning">
                    <strong>{{ __('Review flags:') }}</strong>
                    <ul class="mb-0">
                        @foreach ($paymentRequest->similar_flags as $flag)
                            <li>{{ $flag }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('State') }}</strong>
                            <div><span class="badge bg-{{ $paymentRequest->state === 'paid' || $paymentRequest->state === 'approved' ? 'success' : ($paymentRequest->state === 'rejected' || $paymentRequest->state === 'failed' ? 'danger' : ($paymentRequest->state === 'awaiting_approval' || $paymentRequest->state === 'under_review' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $paymentRequest->state)) }}</span></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Amount') }}</strong>
                            <div>{{ number_format((float) $paymentRequest->amount_original, 2) }} {{ $paymentRequest->currency }}</div>
                            <small class="text-muted">{{ number_format((float) $paymentRequest->amount_base, 2) }} {{ __('base') }}</small>
                            @if ((float) ($paymentRequest->paid_amount_base ?? 0) > 0)
                                <div><small class="text-muted">{{ __('Paid') }}: {{ number_format((float) $paymentRequest->paid_amount_original, 2) }} {{ $paymentRequest->currency }} — {{ __('remaining') }}: {{ number_format((float) $paymentRequest->remainingAmountOriginal(), 2) }} {{ $paymentRequest->currency }}</small></div>
                            @endif
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Beneficiary') }}</strong>
                            <div>{{ ucfirst($paymentRequest->beneficiary_type) }} #{{ $paymentRequest->beneficiary_id }}</div>
                            <small class="text-muted">{{ $paymentRequest->beneficiary_account ?? '—' }}</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Source account') }}</strong>
                            <div>{{ $paymentRequest->sourceAccount->code ?? '—' }}</div>
                            <small class="text-muted">{{ __('Available') }}: {{ $availableBalance !== null ? number_format((float) $availableBalance, 2) : '—' }}</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Due date') }}</strong>
                            <div>{{ $paymentRequest->due_date ? $paymentRequest->due_date->format('Y-m-d') : '—' }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Planned date') }}</strong>
                            <div>{{ $paymentRequest->planned_date ? $paymentRequest->planned_date->format('Y-m-d') : '—' }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Priority') }}</strong>
                            <div>{{ ucfirst($paymentRequest->priority) }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Client reference') }}</strong>
                            <div>{{ $paymentRequest->client_reference ?? '—' }}</div>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>{{ __('Reason') }}</strong>
                            <div>{{ $paymentRequest->reason ?? '—' }}</div>
                        </div>
                        @if ($paymentRequest->sourceDocuments())
                            <div class="col-md-12 mb-3">
                                <strong>{{ __('Source documents') }}</strong>
                                <ul class="mb-0">
                                    @foreach ($paymentRequest->sourceDocuments() as $doc)
                                        <li>{{ ucfirst($doc['type']) }} #{{ $doc['id'] }}
                                            @if ($doc['type'] === 'bill' && isset($billOutstanding[$doc['id']]))
                                                — {{ __('outstanding') }} {{ number_format((float) $billOutstanding[$doc['id']], 2) }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($paymentRequest->approved_snapshot)
                            <div class="col-md-12">
                                <strong>{{ __('Approved snapshot') }}</strong>
                                <div class="text-muted small">{{ __('Approved on') }} {{ $paymentRequest->approved_at ? $paymentRequest->approved_at->format('Y-m-d H:i') : '—' }} — {{ __('rule version') }} {{ $paymentRequest->approved_rule_version ?? '—' }}. {{ __('Amount') }} {{ number_format((float) ($paymentRequest->approved_snapshot['amount_original'] ?? 0), 2) }} {{ $paymentRequest->approved_snapshot['currency'] ?? '' }}, {{ __('beneficiary') }} {{ $paymentRequest->approved_snapshot['beneficiary_type'] ?? '' }} #{{ $paymentRequest->approved_snapshot['beneficiary_id'] ?? '' }}.</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Approval steps') }}</h5></div>
                <div class="card-body">
                    @if ($paymentRequest->steps->isEmpty())
                        <p class="text-muted">{{ __('No approval steps yet. Submit the request to generate them.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Level') }}</th>
                                        <th>{{ __('Rule version') }}</th>
                                        <th>{{ __('Eligible approvers') }}</th>
                                        <th>{{ __('Votes') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Decisions') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($paymentRequest->steps as $step)
                                        <tr>
                                            <td>{{ $step->level }}</td>
                                            <td>{{ $step->rule_version }}</td>
                                            <td>
                                                @foreach ($step->eligibleUserIds() as $eligibleId)
                                                    {{ $users->find($eligibleId)->name ?? ('#' . $eligibleId) }}@if (!$loop->last), @endif
                                                @endforeach
                                                @if ($step->substitute_user_id)
                                                    <br><small class="text-muted">{{ __('Substitute') }}: {{ $users->find($step->substitute_user_id)->name ?? ('#' . $step->substitute_user_id) }} {{ $step->substituteIsValid() ? __('(valid)') : __('(expired)') }}</small>
                                                @endif
                                            </td>
                                            <td>{{ $step->decisions->where('decision', 'approve')->unique('actor_id')->count() }} / {{ $step->required_votes }}</td>
                                            <td><span class="badge bg-{{ $step->status === 'approved' ? 'success' : ($step->status === 'rejected' || $step->status === 'invalidated' ? 'danger' : 'warning') }}">{{ ucfirst($step->status) }}</span></td>
                                            <td>
                                                @foreach ($step->decisions as $decision)
                                                    <div>{{ $users->find($decision->actor_id)->name ?? ('#' . $decision->actor_id) }} — {{ ucfirst($decision->decision) }} ({{ $decision->decided_at ? $decision->decided_at->format('Y-m-d H:i') : '—' }}){{ $decision->reason ? ': ' . $decision->reason : '' }}</div>
                                                @endforeach
                                            </td>
                                            <td class="text-end">
                                                @can('approve treasury payments')
                                                    @if ($step->isPending() && $paymentRequest->isAwaitingApproval() && (in_array(auth()->id(), $step->eligibleUserIds(), true) || ((int) $step->substitute_user_id === (int) auth()->id() && $step->substituteIsValid())))
                                                        <form method="POST" action="{{ route('treasury-approval-steps.decide', $step) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="decision" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('treasury-approval-steps.decide', $step) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="decision" value="reject">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Reject this payment request?') }}')">{{ __('Reject') }}</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('treasury-approval-steps.decide', $step) }}" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="decision" value="return">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Return') }}</button>
                                                        </form>
                                                    @endif
                                                    @if ($step->isPending() && $paymentRequest->isAwaitingApproval())
                                                        <form method="POST" action="{{ route('treasury-approval-steps.substitute', $step) }}" class="d-inline mt-1">
                                                            @csrf
                                                            <select name="substitute_user_id" class="form-control form-control-sm d-inline w-auto" required>
                                                                <option value="">{{ __('Substitute…') }}</option>
                                                                @foreach ($users as $user)
                                                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                                                @endforeach
                                                            </select>
                                                            <input type="date" name="substitute_expiry" class="form-control form-control-sm d-inline w-auto">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Assign') }}</button>
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
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Fund reservations') }}</h5></div>
                <div class="card-body">
                    @if ($paymentRequest->reservations->isEmpty())
                        <p class="text-muted">{{ __('No reservations. A reservation is created when the request is approved, if reservations are configured.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Expires') }}</th>
                                        <th>{{ __('Consumed') }}</th>
                                        <th>{{ __('Released') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($paymentRequest->reservations as $reservation)
                                        <tr>
                                            <td>{{ number_format((float) $reservation->amount, 2) }} {{ $reservation->currency }}</td>
                                            <td><span class="badge bg-{{ $reservation->state === 'active' ? 'warning' : ($reservation->state === 'consumed' ? 'success' : 'secondary') }}">{{ ucfirst($reservation->state) }}</span></td>
                                            <td>{{ $reservation->expires_at ? $reservation->expires_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>{{ number_format((float) $reservation->consumed_amount, 2) }}</td>
                                            <td>{{ number_format((float) $reservation->released_amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Payment attempts') }}</h5></div>
                <div class="card-body">
                    @if ($paymentRequest->attempts->isEmpty())
                        <p class="text-muted">{{ __('No payment attempts yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Attempt') }}</th>
                                        <th>{{ __('Provider') }}</th>
                                        <th>{{ __('Provider reference') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Journal') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Result') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($paymentRequest->attempts as $attempt)
                                        <tr>
                                            <td>{{ $attempt->attempt_number }}</td>
                                            <td>{{ $attempt->provider ?? '—' }}</td>
                                            <td>{{ $attempt->provider_reference ?? '—' }}</td>
                                            <td>{{ $attempt->amount_original !== null ? number_format((float) $attempt->amount_original, 2) . ' ' . ($attempt->currency ?? $paymentRequest->currency) : '—' }}</td>
                                            <td>{{ $attempt->journal_id ? '#' . $attempt->journal_id : '—' }}</td>
                                            <td>{{ ucfirst(str_replace('_', ' ', $attempt->state)) }}</td>
                                            <td>{{ $attempt->result ?? '—' }}</td>
                                            <td class="text-end">
                                                @can('execute treasury payments')
                                                    @if ($attempt->isProcessing())
                                                        <form method="POST" action="{{ route('treasury-payment-attempts.report-timeout', $attempt) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('{{ __('Report that this execution timed out? The outcome becomes unknown.') }}')">{{ __('Report timeout') }}</button>
                                                        </form>
                                                    @endif
                                                    @if ($attempt->isOutcomeUnknown())
                                                        <form method="POST" action="{{ route('treasury-payment-attempts.confirm-provider-paid', $attempt) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('{{ __('Confirm the provider says this payment went through? The movement will be posted.') }}')">{{ __('Provider confirmed paid') }}</button>
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
                </div>
            </div>
        </div>
    </div>
@endsection
