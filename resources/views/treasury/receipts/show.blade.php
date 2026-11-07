@extends('layouts.admin')
@section('page-title')
    {{ __('Receipt') }} {{ $receipt->receipt_number ?? $receipt->operation->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-receipts.index') }}">{{ __('Treasury Receipts') }}</a></li>
    <li class="breadcrumb-item">{{ $receipt->receipt_number ?? $receipt->operation->reference ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @if ($receipt->isConfirmed())
            <a href="{{ route('treasury-receipts.pdf', $receipt) }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-file-download"></i> {{ __('Receipt PDF') }}
            </a>
        @endif
        @can('create treasury receipts')
            @if ($receipt->isDraft())
                <a href="{{ route('treasury-receipts.edit', $receipt) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
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
                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8"><span class="badge bg-{{ $receipt->state === 'confirmed' ? 'success' : ($receipt->state === 'reversed' ? 'danger' : ($receipt->state === 'pending' ? 'warning' : 'secondary')) }}">{{ ucfirst($receipt->state) }}</span></dd>

                        <dt class="col-sm-4">{{ __('Reference') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->operation->reference ?? __('Not issued yet — allocated on submit') }}</dd>

                        <dt class="col-sm-4">{{ __('Receipt Number') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->receipt_number ?? __('Issued on confirmation') }}</dd>

                        <dt class="col-sm-4">{{ __('Customer') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->customer->name ?? ($receipt->classification ?? '—') }}</dd>

                        <dt class="col-sm-4">{{ __('Financial Account') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->operation->financialAccount ? $receipt->operation->financialAccount->displayName() : '—' }}</dd>

                        <dt class="col-sm-4">{{ __('Method') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->methodLabel() }}</dd>

                        <dt class="col-sm-4">{{ __('Gross Amount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $receipt->operation->amount_original, 2) }} {{ $receipt->operation->currency }}</dd>

                        <dt class="col-sm-4">{{ __('Fees / Withholding / Discount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $receipt->fees, 2) }} / {{ number_format((float) $receipt->withholding, 2) }} / {{ number_format((float) $receipt->discount, 2) }} {{ $receipt->operation->currency }}</dd>

                        <dt class="col-sm-4">{{ __('Net Cash Credited') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $receipt->netCashAmount(), 2) }} {{ $receipt->operation->currency }}</dd>

                        <dt class="col-sm-4">{{ __('Settlement Value') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $receipt->settlementValue(), 2) }} {{ $receipt->operation->currency }} ({{ number_format((float) $receipt->settlementValueBase(), 2) }} {{ __('base') }})</dd>

                        <dt class="col-sm-4">{{ __('Unapplied Credit (this receipt)') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $remaining, 2) }} {{ $receipt->operation->currency }} ({{ number_format((float) $remainingBase, 2) }} {{ __('base') }})</dd>

                        <dt class="col-sm-4">{{ __('Exchange Rate') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->operation->exchange_rate ?? '—' }} {{ $receipt->operation->rate_source ? '(' . $receipt->operation->rate_source . ', ' . ($receipt->operation->rate_date ? $receipt->operation->rate_date->format('Y-m-d') : '') . ')' : '' }}</dd>

                        <dt class="col-sm-4">{{ __('Posting / Effective / Value Date') }}</dt>
                        <dd class="col-sm-8">{{ $receipt->operation->posting_date }} / {{ $receipt->operation->effective_date }} / {{ $receipt->operation->value_date }}</dd>

                        @if ($receipt->evidence)
                            <dt class="col-sm-4">{{ __('Evidence') }}</dt>
                            <dd class="col-sm-8">{{ $receipt->evidence }}</dd>
                        @endif
                        @if ($receipt->reversal_reason)
                            <dt class="col-sm-4">{{ __('Reason') }}</dt>
                            <dd class="col-sm-8">{{ $receipt->reversal_reason }}</dd>
                        @endif
                    </dl>

                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        @can('confirm treasury receipts')
                            @if ($receipt->isDraft())
                                <form method="POST" action="{{ route('treasury-receipts.submit', $receipt) }}">@csrf<button type="submit" class="btn btn-primary">{{ __('Submit') }}</button></form>
                            @endif
                            @if ($receipt->isPending())
                                <form method="POST" action="{{ route('treasury-receipts.confirm', $receipt) }}">@csrf<button type="submit" class="btn btn-success">{{ __('Confirm') }}</button></form>
                                <form method="POST" action="{{ route('treasury-receipts.reject', $receipt) }}" class="d-flex gap-2">@csrf<input type="text" name="reason" class="form-control" placeholder="{{ __('Rejection reason') }}" required><button type="submit" class="btn btn-warning">{{ __('Reject') }}</button></form>
                            @endif
                        @endcan
                        @can('create treasury receipts')
                            @if ($receipt->isDraft() || $receipt->isPending())
                                <form method="POST" action="{{ route('treasury-receipts.cancel', $receipt) }}">@csrf<button type="submit" class="btn btn-secondary">{{ __('Cancel') }}</button></form>
                            @endif
                            @if ($receipt->isDraft() || $receipt->isPending())
                                <form method="POST" action="{{ route('treasury-receipts.evidence', $receipt) }}" class="d-flex gap-2">@csrf<input type="text" name="evidence" class="form-control" placeholder="{{ __('Evidence note / file reference') }}" value="{{ $receipt->evidence }}"><button type="submit" class="btn btn-outline-secondary">{{ __('Attach Evidence') }}</button></form>
                            @endif
                        @endcan
                        @can('reverse treasury operations')
                            @if ($receipt->isConfirmed())
                                <form method="POST" action="{{ route('treasury-receipts.reverse', $receipt) }}" class="d-flex gap-2">@csrf<input type="text" name="reason" class="form-control" placeholder="{{ __('Reversal reason') }}" required><button type="submit" class="btn btn-danger" onclick="return confirm('{{ __('Reverse this receipt? Its allocations are reopened and the balance change is reversed.') }}')">{{ __('Reverse') }}</button></form>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5>{{ __('Allocations') }}</h5></div>
                <div class="card-body">
                    @if ($receipt->allocations->isEmpty())
                        <p class="text-muted">{{ __('No allocations yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Invoice') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Base') }}</th>
                                        <th>{{ __('Source') }}</th>
                                        <th>{{ __('State') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($receipt->allocations as $allocation)
                                        <tr>
                                            <td>{{ $allocation->invoice ? 'INV-' . $allocation->invoice->invoice_id : '—' }}</td>
                                            <td>{{ number_format((float) $allocation->allocated_amount, 2) }} {{ $allocation->currency }}</td>
                                            <td>{{ number_format((float) $allocation->allocated_amount_base, 2) }}</td>
                                            <td>{{ $allocation->source === 'unapplied_credit' ? __('Unapplied credit') : __('Receipt') }}</td>
                                            <td>{{ ucfirst($allocation->state) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @can('allocate treasury receipts')
                        @if ($receipt->isConfirmed() && $openInvoices->isNotEmpty() && bccomp($remaining, '0', 4) > 0)
                            <hr>
                            <h6>{{ __('Allocate to invoices') }}</h6>
                            <form method="POST" action="{{ route('treasury-receipts.allocate', $receipt) }}">
                                @csrf
                                @foreach ($openInvoices as $index => $invoice)
                                    <div class="row mb-2">
                                        <div class="col-md-5">
                                            <label class="form-label">{{ __('Invoice') }} INV-{{ $invoice->invoice_id }} — {{ __('due') }} {{ number_format((float) app(\App\Services\TreasuryReceiptService::class)->invoiceDue($invoice), 2) }}</label>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="hidden" name="allocations[{{ $index }}][invoice_id]" value="{{ $invoice->id }}">
                                            <input type="number" step="0.0001" min="0" name="allocations[{{ $index }}][amount]" class="form-control" placeholder="{{ __('Amount in') }} {{ $receipt->operation->currency }}">
                                        </div>
                                    </div>
                                @endforeach
                                @if ($receipt->operation->currency !== \App\Services\TreasurySettings::baseCurrency((int) auth()->user()->creatorId()))
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Cross-currency approver') }} *</label>
                                            <select name="approved_by" class="form-control">
                                                <option value="">{{ __('—') }}</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                                @endforeach
                                            </select>
                                            <small class="text-muted">{{ __('A foreign-currency allocation needs an approver who is not you.') }}</small>
                                        </div>
                                    </div>
                                @endif
                                <button type="submit" class="btn btn-primary">{{ __('Post Allocations') }}</button>
                            </form>
                        @endif

                        @if ($receipt->isConfirmed() && $receipt->customer_id && $openInvoices->isNotEmpty() && bccomp($unappliedCredit, '0', 4) > 0)
                            <hr>
                            <h6>{{ __('Apply unapplied credit') }} — {{ __('available') }} {{ number_format((float) $unappliedCredit, 2) }} {{ $receipt->operation->currency }}</h6>
                            <p class="text-muted">{{ __('Applies credit from earlier receipts (oldest first). No new cash is recorded.') }}</p>
                            <form method="POST" action="{{ route('treasury-receipts.apply-credit', $receipt) }}">
                                @csrf
                                <div class="row mb-2">
                                    <div class="col-md-5">
                                        <select name="invoice_id" class="form-control" required>
                                            @foreach ($openInvoices as $invoice)
                                                <option value="{{ $invoice->id }}">{{ __('Invoice') }} INV-{{ $invoice->invoice_id }} — {{ __('due') }} {{ number_format((float) app(\App\Services\TreasuryReceiptService::class)->invoiceDue($invoice), 2) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="number" step="0.0001" min="0.0001" name="amount" class="form-control" placeholder="{{ __('Amount') }}" required>
                                    </div>
                                    @if ($receipt->operation->currency !== \App\Services\TreasurySettings::baseCurrency((int) auth()->user()->creatorId()))
                                        <div class="col-md-3">
                                            <select name="approved_by" class="form-control">
                                                <option value="">{{ __('Approver (foreign currency)') }}</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                </div>
                                <button type="submit" class="btn btn-outline-primary">{{ __('Apply Credit') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection
