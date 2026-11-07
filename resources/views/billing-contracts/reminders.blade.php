@extends('layouts.admin')
@section('page-title')
    {{ $contract->reference }} — {{ __('Reminders') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ __('Reminders') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('billing-contracts.show', $contract->id) }}" class="btn btn-sm btn-secondary">{{ __('Back to contract') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Overdue contract invoices get reminders: first reminder :first days after the due date, then every :interval days, at most :max reminders. A reminder is an email with the same invoice and the same PDF — never a new invoice. Reminders stop when the invoice is paid.', ['first' => $settings['first_days'], 'interval' => $settings['interval_days'], 'max' => $settings['max']]) }}</small></p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Invoice') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Sent') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Next reminder') }}</th><th>{{ __('Next retry') }}</th><th>{{ __('Last sent') }}</th><th>{{ __('Last error') }}</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($reminders as $reminder)
                                    <tr>
                                        <td>
                                            @if ($reminder->invoice)
                                                <a href="{{ route('invoice.show', \Crypt::encrypt($reminder->invoice->id)) }}">{{ \Auth::user()->invoiceNumberFormat($reminder->invoice->invoice_id) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $reminder->invoice ? $reminder->invoice->due_date : '—' }}</td>
                                        <td><span class="badge bg-{{ $reminder->status === 'sent' ? 'success' : ($reminder->status === 'failed' ? 'danger' : ($reminder->status === 'stopped' ? 'secondary' : 'warning')) }}">{{ ucfirst($reminder->status) }}</span></td>
                                        <td>{{ $reminder->sent_count }} / {{ $settings['max'] }}</td>
                                        <td>{{ $reminder->attempt_count }}</td>
                                        <td>{{ $reminder->next_reminder_at ? $reminder->next_reminder_at->format('Y-m-d') : '—' }}</td>
                                        <td>{{ $reminder->next_retry_at ? $reminder->next_retry_at->format('Y-m-d H:i') : '—' }}</td>
                                        <td>{{ $reminder->last_sent_at ? $reminder->last_sent_at->format('Y-m-d H:i') : '—' }}</td>
                                        <td>@if ($reminder->last_error)<small class="text-danger">{{ $reminder->last_error }}</small>@else — @endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted">{{ __('No reminders yet — none of this contract’s invoices is overdue.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $reminders->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
