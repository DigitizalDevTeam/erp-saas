@extends('layouts.admin')
@section('page-title')
    {{ $contract->reference }} — {{ $contract->title }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item">{{ $contract->reference }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage billing contracts')
            @if ($contract->state === 'draft')
                <a href="{{ route('billing-contracts.edit', $contract->id) }}" class="btn btn-sm btn-secondary">{{ __('Edit') }}</a>
            @endif
            @if (in_array($contract->state, ['active', 'suspended']))
                <a href="{{ route('billing-contracts.amend-form', $contract->id) }}" class="btn btn-sm btn-secondary">{{ __('Amend') }}</a>
            @endif
            @if ($contract->state === 'active')
                <form method="POST" action="{{ route('billing-contracts.generate-now', $contract->id) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Generate invoices now') }}</button>
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
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Reference') }}</dt>
                        <dd class="col-sm-8">{{ $contract->reference }}</dd>
                        <dt class="col-sm-4">{{ __('Customer') }}</dt>
                        <dd class="col-sm-8">{{ $contract->customer->name ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8"><span class="badge bg-{{ $contract->state === 'active' ? 'success' : ($contract->state === 'draft' ? 'secondary' : ($contract->state === 'pending_approval' ? 'warning' : 'danger')) }}">{{ ucfirst(str_replace('_', ' ', $contract->state)) }}</span></dd>
                        <dt class="col-sm-4">{{ __('Term') }}</dt>
                        <dd class="col-sm-8">{{ $contract->start_date->format('Y-m-d') }} → {{ $contract->end_date ? $contract->end_date->format('Y-m-d') : __('Open-ended') }}</dd>
                        <dt class="col-sm-4">{{ __('Schedule') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($contract->billing_frequency) }}{{ $contract->billing_frequency === 'custom' ? ' — ' . __('every') . ' ' . $contract->custom_interval . ' ' . $contract->custom_unit : '' }} · {{ __('anchor day') }} {{ $contract->anchor_day }} · {{ $contract->billing_mode === 'advance' ? __('in advance') : __('in arrears') }} · {{ __('due +') }}{{ $contract->payment_terms_days }} {{ __('days') }}</dd>
                        <dt class="col-sm-4">{{ __('Approval mode') }}</dt>
                        <dd class="col-sm-8">{{ $contract->approval_mode === 'approval' ? __('Approval') : __('Automatic') }}</dd>
                        <dt class="col-sm-4">{{ __('Partial period') }}</dt>
                        <dd class="col-sm-8">{{ $contract->partial_period_rule === 'prorate' ? __('Prorate by days') : ($contract->partial_period_rule === 'skip' ? __('Skip') : __('Full charge')) }}</dd>
                        <dt class="col-sm-4">{{ __('Currency') }}</dt>
                        <dd class="col-sm-8">{{ $contract->currency }}{{ $contract->currency !== 'MZN' ? ' · ' . __('rate') . ' ' . $contract->exchange_rate : '' }}</dd>
                        @if ($contract->description)
                            <dt class="col-sm-4">{{ __('Description') }}</dt>
                            <dd class="col-sm-8">{{ $contract->description }}</dd>
                        @endif
                        @if ($contract->rejected_reason)
                            <dt class="col-sm-4">{{ __('Rejection reason') }}</dt>
                            <dd class="col-sm-8 text-danger">{{ $contract->rejected_reason }}</dd>
                        @endif
                        @if ($contract->suspended_reason)
                            <dt class="col-sm-4">{{ __('Suspension reason') }}</dt>
                            <dd class="col-sm-8 text-danger">{{ $contract->suspended_reason }}</dd>
                        @endif
                    </dl>

                    <h6 class="mt-3">{{ __('Billing lines') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Description') }}</th><th>{{ __('Qty') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Amount') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($contract->lines as $line)
                                    <tr>
                                        <td>{{ $line->description }}</td>
                                        <td>{{ $line->quantity }}</td>
                                        <td>{{ number_format((float) $line->unit_price, 2) }}</td>
                                        <td>{{ number_format((float) $line->discount_amount, 2) }}</td>
                                        <td>{{ number_format((float) $line->amount, 2) }} {{ $contract->currency }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><th colspan="4">{{ __('Per period') }}</th><th>{{ number_format((float) $contract->lines->sum('amount'), 2) }} {{ $contract->currency }}</th></tr>
                            </tfoot>
                        </table>
                    </div>

                    <h6 class="mt-4">{{ __('Occurrences') }} <a href="{{ route('billing-contracts.occurrences', $contract->id) }}" class="btn btn-sm btn-light">{{ __('View all') }}</a></h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Coverage') }}</th><th>{{ __('Billing date') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('State') }}</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($occurrences as $occurrence)
                                    <tr>
                                        <td><a href="{{ route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id]) }}">{{ $occurrence->period_start->format('Y-m-d') }} → {{ $occurrence->coverageEndInclusive() }}</a></td>
                                        <td>{{ $occurrence->billing_date->format('Y-m-d') }}</td>
                                        <td>{{ $occurrence->due_date->format('Y-m-d') }}</td>
                                        <td>{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</td>
                                        <td>{{ ucfirst($occurrence->state) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No occurrences yet — they are generated on activation.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $occurrences->links() }}

                    <h6 class="mt-4">{{ __('Invoices') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Period') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Issue date') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Balance due') }}</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($invoices as $occurrence)
                                    <tr>
                                        <td><a href="{{ route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id]) }}">{{ $occurrence->period_start->format('Y-m-d') }} → {{ $occurrence->coverageEndInclusive() }}</a></td>
                                        <td>
                                            @if ($occurrence->invoice)
                                                <a href="{{ route('invoice.show', \Crypt::encrypt($occurrence->invoice_id)) }}">{{ \Auth::user()->invoiceNumberFormat($occurrence->invoice->invoice_id) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $occurrence->invoice ? $occurrence->invoice->issue_date : '—' }}</td>
                                        <td>{{ $occurrence->invoice ? $occurrence->invoice->due_date : '—' }}</td>
                                        <td>{{ $occurrence->invoice ? number_format((float) $occurrence->invoice->getTotal(), 2) : '—' }}</td>
                                        <td>{{ $occurrence->invoice ? number_format((float) $occurrence->invoice->getDue(), 2) : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">{{ __('No invoices issued yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Lifecycle') }}</h6>
                    @can('manage billing contracts')
                        @if ($contract->state === 'draft')
                            <form method="POST" action="{{ route('billing-contracts.submit', $contract->id) }}" class="mb-2">
                                @csrf
                                <button class="btn btn-primary btn-sm w-100" type="submit">{{ $contract->approval_mode === 'automatic' ? __('Activate (automatic mode)') : __('Submit for approval') }}</button>
                            </form>
                        @endif
                        @if ($contract->state === 'active')
                            <form method="POST" action="{{ route('billing-contracts.suspend', $contract->id) }}" class="mb-2">
                                @csrf
                                <input type="text" name="reason" class="form-control mb-1" placeholder="{{ __('Suspension reason') }}" required>
                                <button class="btn btn-warning btn-sm w-100" type="submit">{{ __('Suspend') }}</button>
                            </form>
                            <a href="{{ route('billing-contracts.end-form', $contract->id) }}" class="btn btn-danger btn-sm w-100 mb-2">{{ __('End contract…') }}</a>
                        @endif
                        @if ($contract->state === 'suspended')
                            <form method="POST" action="{{ route('billing-contracts.resume', $contract->id) }}" class="mb-2">
                                @csrf
                                <select name="missed_decision" class="form-control mb-1">
                                    <option value="bill">{{ __('Missed periods: bill them (catch-up)') }}</option>
                                    <option value="skip">{{ __('Missed periods: skip them') }}</option>
                                </select>
                                <button class="btn btn-success btn-sm w-100" type="submit">{{ __('Resume') }}</button>
                            </form>
                            <a href="{{ route('billing-contracts.end-form', $contract->id) }}" class="btn btn-danger btn-sm w-100 mb-2">{{ __('End contract…') }}</a>
                        @endif
                        @if (in_array($contract->state, ['draft', 'pending_approval', 'active', 'suspended']))
                            <form method="POST" action="{{ route('billing-contracts.cancel', $contract->id) }}" class="mb-2">
                                @csrf
                                <input type="text" name="reason" class="form-control mb-1" placeholder="{{ __('Cancellation reason') }}" required>
                                <button class="btn btn-outline-danger btn-sm w-100" type="submit">{{ __('Cancel contract') }}</button>
                            </form>
                        @endif
                    @endcan
                    @can('approve billing contracts')
                        @if ($contract->state === 'pending_approval')
                            <form method="POST" action="{{ route('billing-contracts.approve', $contract->id) }}" class="mb-2">
                                @csrf
                                <button class="btn btn-success btn-sm w-100" type="submit">{{ __('Approve & activate') }}</button>
                            </form>
                            <form method="POST" action="{{ route('billing-contracts.reject', $contract->id) }}" class="mb-2">
                                @csrf
                                <input type="text" name="reason" class="form-control mb-1" placeholder="{{ __('Rejection reason') }}" required>
                                <button class="btn btn-danger btn-sm w-100" type="submit">{{ __('Reject') }}</button>
                            </form>
                        @endif
                    @endcan

                    @if ($contract->revisions->isNotEmpty())
                        <h6 class="mt-4">{{ __('Revisions') }}</h6>
                        <ul class="list-unstyled">
                            @foreach ($contract->revisions as $revision)
                                <li>#{{ $revision->revision_number }} — {{ $revision->effective_date->format('Y-m-d') }} — {{ $revision->reason }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($contract->auditEvents->isNotEmpty())
                        <h6 class="mt-4">{{ __('History') }}</h6>
                        <ul class="list-unstyled">
                            @foreach ($contract->auditEvents->take(10) as $event)
                                <li><small>{{ $event->created_at->format('Y-m-d H:i') }} — {{ ucfirst($event->action) }}{{ $event->reason ? ' — ' . $event->reason : '' }}</small></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
