@extends('layouts.admin')
@section('page-title')
    {{ __('Cash Session') }} #{{ $session->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.index') }}">{{ __('Cash Registers') }}</a></li>
    <li class="breadcrumb-item">{{ __('Session') }} #{{ $session->id }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury cash sessions')
            @if ($session->isOpen())
                <a href="{{ route('treasury-cash-sessions.count-form', $session) }}" class="btn btn-sm btn-outline-primary">{{ __('Count Cash') }}</a>
                <a href="{{ route('treasury-cash-sessions.handover-form', $session) }}" class="btn btn-sm btn-outline-primary">{{ __('Hand Over Cash') }}</a>
                <a href="{{ route('treasury-cash-sessions.close-form', $session) }}" class="btn btn-sm btn-primary">{{ __('Close Session') }}</a>
            @elseif ($session->isClosed())
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reopenModal">{{ __('Reopen') }}</button>
                <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#lockModal">{{ __('Lock') }}</button>
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
                        <dt class="col-sm-4">{{ __('Register') }}</dt>
                        <dd class="col-sm-8">{{ $session->register ? $session->register->displayName() : '—' }} — {{ $session->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Operator') }}</dt>
                        <dd class="col-sm-8">{{ $session->operator ? $session->operator->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8"><span class="badge bg-{{ $session->state === 'closed' ? 'success' : ($session->state === 'locked' ? 'dark' : 'secondary') }}">{{ ucfirst($session->state) }}</span></dd>
                        <dt class="col-sm-4">{{ __('Opened') }}</dt>
                        <dd class="col-sm-8">{{ $session->opened_at ? $session->opened_at->format('Y-m-d H:i') : '—' }}</dd>
                        @if ($session->closed_at)
                            <dt class="col-sm-4">{{ __('Closed') }}</dt>
                            <dd class="col-sm-8">{{ $session->closed_at->format('Y-m-d H:i') }}</dd>
                        @endif
                        @if ($session->shift)
                            <dt class="col-sm-4">{{ __('POS Shift') }}</dt>
                            <dd class="col-sm-8">{{ __('Shift') }} #{{ $session->shift->id }} — {{ __('cash takings') }} {{ number_format((float) $session->shift_cash_total, 2) }} {{ $session->currency }}, {{ __('card') }} {{ number_format((float) $session->shift_card_total, 2) }}, {{ __('credit') }} {{ number_format((float) $session->shift_credit_total, 2) }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Opening Float') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $session->opening_float, 2) }} {{ $session->currency }}
                            @if ((float) $session->opening_float_difference != 0)
                                <span class="badge bg-warning text-dark">{{ __('differs from register balance by') }} {{ number_format((float) $session->opening_float_difference, 2) }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Expected Cash') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $session->expected_balance, 2) }} {{ $session->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Counted Cash') }}</dt>
                        <dd class="col-sm-8">{{ $session->counted_balance !== null ? number_format((float) $session->counted_balance, 2) . ' ' . $session->currency : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Variance') }}</dt>
                        <dd class="col-sm-8">
                            @if ($session->variance_amount !== null)
                                <span class="{{ (float) $session->variance_amount < 0 ? 'text-danger' : ((float) $session->variance_amount > 0 ? 'text-success' : '') }}">{{ number_format((float) $session->variance_amount, 2) }} {{ $session->currency }}</span>
                                {{ (float) $session->variance_amount < 0 ? __('(shortage)') : ((float) $session->variance_amount > 0 ? __('(surplus)') : '') }}
                            @else
                                —
                            @endif
                        </dd>
                        @if ($session->variance_reason)
                            <dt class="col-sm-4">{{ __('Variance Reason') }}</dt>
                            <dd class="col-sm-8">{{ $session->variance_reason }}</dd>
                        @endif
                        @if ($session->variance_approved_by)
                            <dt class="col-sm-4">{{ __('Variance Approved By') }}</dt>
                            <dd class="col-sm-8">{{ $session->varianceApprover ? $session->varianceApprover->name : '#' . $session->variance_approved_by }}{{ $session->variance_approved_at ? ' — ' . $session->variance_approved_at->format('Y-m-d H:i') : '' }}</dd>
                        @endif
                        @if ((float) $session->variance_posted_amount != 0)
                            <dt class="col-sm-4">{{ __('Variance Posted') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $session->variance_posted_amount, 2) }} {{ $session->currency }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Card Total') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $session->card_total, 2) }} {{ $session->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Mobile Total') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $session->mobile_total, 2) }} {{ $session->currency }}</dd>
                        @if ($session->reopen_reason)
                            <dt class="col-sm-4">{{ __('Reopen Reason') }}</dt>
                            <dd class="col-sm-8">{{ $session->reopen_reason }}{{ $session->reopened_at ? ' — ' . $session->reopened_at->format('Y-m-d H:i') : '' }}</dd>
                        @endif
                        @if ($session->lock_reason)
                            <dt class="col-sm-4">{{ __('Lock Reason') }}</dt>
                            <dd class="col-sm-8">{{ $session->lock_reason }}{{ $session->locked_at ? ' — ' . $session->locked_at->format('Y-m-d H:i') : '' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0">{{ __('Denomination Count') }}</h6></div>
                <div class="card-body">
                    @if ($session->counts->isEmpty())
                        <p class="text-muted">{{ __('No count recorded yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>{{ __('Denomination') }}</th><th>{{ __('Count') }}</th><th>{{ __('Amount') }}</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($session->counts->sortByDesc('denomination') as $count)
                                        <tr>
                                            <td>{{ number_format((float) $count->denomination, 2) }}</td>
                                            <td>{{ $count->count }}</td>
                                            <td>{{ number_format((float) $count->amount, 2) }} {{ $session->currency }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0">{{ __('Movements') }}</h6></div>
                <div class="card-body">
                    @if ($session->movements->isEmpty())
                        <p class="text-muted">{{ __('No movements recorded yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>{{ __('Type') }}</th><th>{{ __('Method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Recorded') }}</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($session->movements as $movement)
                                        <tr>
                                            <td>{{ ucfirst(str_replace('_', ' ', $movement->type)) }}</td>
                                            <td>{{ ucfirst($movement->method) }}</td>
                                            <td>{{ $movement->removesCash() ? '−' : '+' }}{{ number_format((float) $movement->amount, 2) }} {{ $session->currency }}</td>
                                            <td>{{ $movement->reference ?? '—' }}</td>
                                            <td>{{ $movement->reason ?? '—' }}</td>
                                            <td>{{ $movement->created_at ? $movement->created_at->format('Y-m-d H:i') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            @can('manage treasury cash sessions')
                @if ($session->isOpen())
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">{{ __('Record Movement') }}</h6></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('treasury-cash-sessions.record-movement', $session) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Type') }} <span class="text-danger">*</span></label>
                                    <select name="type" class="form-control" required>
                                        <option value="inflow">{{ __('Cash inflow') }}</option>
                                        <option value="outflow">{{ __('Cash outflow') }}</option>
                                        <option value="float_top_up">{{ __('Float top-up') }}</option>
                                        <option value="withdrawal">{{ __('Withdrawal') }}</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Method') }}</label>
                                    <select name="method" class="form-control">
                                        <option value="cash">{{ __('Cash') }}</option>
                                        <option value="card">{{ __('Card') }}</option>
                                        <option value="mobile">{{ __('Mobile') }}</option>
                                    </select>
                                    <small class="text-muted">{{ __('Only cash moves the register balance. Card and mobile are shown separately.') }}</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Reference') }}</label>
                                    <input type="text" name="reference" class="form-control" maxlength="100">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Reason') }}</label>
                                    <input type="text" name="reason" class="form-control" maxlength="1000">
                                </div>
                                <button type="submit" class="btn btn-primary">{{ __('Record') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>

    @if ($session->isClosed())
        <div class="modal fade" id="reopenModal" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('treasury-cash-sessions.reopen', $session) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">{{ __('Reopen Session') }}</h5></div>
                        <div class="modal-body">
                            <p class="text-muted">{{ __('Reopening needs a reason and a different user than the operator. The count and the variance already posted stay as they are — a re-close posts only the delta.') }}</p>
                            <label class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required maxlength="1000"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Reopen') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal fade" id="lockModal" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('treasury-cash-sessions.lock', $session) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">{{ __('Lock Session') }}</h5></div>
                        <div class="modal-body">
                            <p class="text-muted">{{ __('A locked session is terminal — it cannot be reopened.') }}</p>
                            <label class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required maxlength="1000"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-dark">{{ __('Lock') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
