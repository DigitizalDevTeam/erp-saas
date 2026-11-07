@extends('layouts.admin')
@section('page-title')
    {{ __('Budget Approvals') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Budget Approvals') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('cost-centre-budgets.index') }}" class="btn btn-sm btn-secondary">{{ __('Budgets') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('cost-centre-budget-approvals.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\CostCentreBudgetApproval::STATUSES as $status)
                                    <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <p class="text-muted">{{ __('Over-budget requests go requester → cost centre manager → finance controller. Small amounts (at or below the threshold in Settings) skip the controller step. Nobody can approve a request they raised themselves.') }}</p>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Month') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Requested by') }}</th>
                                    <th>{{ __('Waiting on') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Decide') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($approvals as $approval)
                                    <tr>
                                        <td>{{ $approval->centre?->code }} — {{ $approval->centre?->name }}</td>
                                        <td>{{ $approval->fiscal_year }}-{{ str_pad((string) $approval->period, 2, '0', STR_PAD_LEFT) }}</td>
                                        <td class="text-end">{{ number_format((float) $approval->amount, 2) }}</td>
                                        <td>{{ \App\Models\User::find($approval->requested_by)?->name ?? '#'.$approval->requested_by }}</td>
                                        <td>
                                            @if ($approval->currentStep() === 'manager')
                                                {{ __('Manager') }} ({{ \App\Models\User::find($approval->manager_user_id)?->name ?? '—' }})
                                            @elseif ($approval->currentStep() === 'controller')
                                                {{ __('Finance controller') }} ({{ \App\Models\User::find($approval->controller_user_id)?->name ?? '—' }})
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($approval->status === 'approved')
                                                <span class="badge bg-success">{{ __('Approved') }}</span>
                                            @elseif ($approval->status === 'rejected')
                                                <span class="badge bg-danger">{{ __('Rejected') }}</span>
                                            @elseif ($approval->status === 'manager_approved')
                                                <span class="badge bg-info">{{ __('Manager approved — with controller') }}</span>
                                            @else
                                                <span class="badge bg-warning">{{ __('Pending') }}</span>
                                            @endif
                                            @if ($approval->auto_note)
                                                <br><small class="text-muted">{{ $approval->auto_note }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($approval->currentStep() !== null)
                                                <form method="POST" action="{{ route('cost-centre-budget-approvals.decide', $approval) }}" class="d-flex gap-1">
                                                    @csrf
                                                    <input type="text" name="note" class="form-control form-control-sm" placeholder="{{ __('Note (optional)') }}" maxlength="1000">
                                                    <button type="submit" name="decision" value="approved" class="btn btn-sm btn-primary">{{ __('Approve') }}</button>
                                                    <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-danger">{{ __('Reject') }}</button>
                                                </form>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">{{ __('No approval requests found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Delegated approvers') }}</h5>
                    <p class="text-muted">{{ __('A manager or finance controller can delegate their step to another user of this company for a validity window. Delegation adds an approver — the delegator can still decide.') }}</p>

                    <div class="table-responsive mb-3">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Delegator') }}</th>
                                    <th>{{ __('Delegate') }}</th>
                                    <th>{{ __('Step') }}</th>
                                    <th>{{ __('Valid from') }}</th>
                                    <th>{{ __('Valid to') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($delegations as $delegation)
                                    <tr>
                                        <td>{{ \App\Models\User::find($delegation->delegator_user_id)?->name ?? '#'.$delegation->delegator_user_id }}</td>
                                        <td>{{ \App\Models\User::find($delegation->delegate_user_id)?->name ?? '#'.$delegation->delegate_user_id }}</td>
                                        <td>{{ ucfirst($delegation->role) }}</td>
                                        <td>{{ $delegation->valid_from?->toDateString() ?? '—' }}</td>
                                        <td>{{ $delegation->valid_to?->toDateString() ?? '—' }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('cost-centre-budget-delegations.destroy', $delegation) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-light">{{ __('Remove') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">{{ __('No delegations.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" action="{{ route('cost-centre-budget-delegations.store') }}" class="row g-2">
                        @csrf
                        <div class="col-md-3">
                            <select name="delegator_user_id" class="form-control" required>
                                <option value="">{{ __('Delegator') }}</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="delegate_user_id" class="form-control" required>
                                <option value="">{{ __('Delegate') }}</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="role" class="form-control" required>
                                <option value="manager">{{ __('Manager step') }}</option>
                                <option value="controller">{{ __('Controller step') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="valid_from" class="form-control" placeholder="{{ __('From') }}">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="valid_to" class="form-control" placeholder="{{ __('To') }}">
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('Add Delegation') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
