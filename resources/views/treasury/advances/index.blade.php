@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Advances') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Advances') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury advances')
            <a href="{{ route('treasury-advances.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Advance') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-advances.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryAdvance::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="recipient_type" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All recipients') }}</option>
                                <option value="employee" {{ request('recipient_type') === 'employee' ? 'selected' : '' }}>{{ __('Employee') }}</option>
                                <option value="supplier" {{ request('recipient_type') === 'supplier' ? 'selected' : '' }}>{{ __('Supplier') }}</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="overdue" value="1" id="overdue" class="form-check-input" {{ request('overdue') ? 'checked' : '' }} onchange="this.form.submit()">
                                <label class="form-check-label" for="overdue">{{ __('Overdue only') }}</label>
                            </div>
                        </div>
                    </form>

                    @if ($advances->isEmpty())
                        <p class="text-muted">{{ __('No advances yet. Request money for an employee or a supplier, approve it, disburse it, then collect the expense lines that prove how it was spent.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Recipient') }}</th>
                                        <th>{{ __('Purpose') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Outstanding') }}</th>
                                        <th>{{ __('Due') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($advances as $advance)
                                        <tr>
                                            <td>{{ $advance->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ $advance->recipientLabel() }}</td>
                                            <td>{{ $advance->purpose ?? '—' }}</td>
                                            <td>{{ number_format((float) $advance->amount_requested, 2) }} {{ $advance->currency }}</td>
                                            <td>{{ number_format((float) $advance->amount_outstanding, 2) }} {{ $advance->currency }}</td>
                                            <td>
                                                {{ $advance->due_date ?? '—' }}
                                                @if ($advance->isOverdue())
                                                    <span class="badge bg-danger">{{ __('Overdue') }}</span>
                                                @endif
                                            </td>
                                            <td><span class="badge bg-{{ in_array($advance->state, ['rejected', 'cancelled']) ? 'danger' : ($advance->state === 'regularised' ? 'success' : ($advance->state === 'closed' ? 'dark' : ($advance->state === 'disbursed' ? 'primary' : ($advance->state === 'pending_approval' || $advance->state === 'under_review' ? 'warning' : 'secondary')))) }}">{{ ucfirst(str_replace('_', ' ', $advance->state)) }}</span></td>
                                            <td><a href="{{ route('treasury-advances.show', $advance) }}" class="btn btn-sm btn-outline-primary">{{ __('Open') }}</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $advances->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
