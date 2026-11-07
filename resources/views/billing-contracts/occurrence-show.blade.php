@extends('layouts.admin')
@section('page-title')
    {{ $occurrence->period_key }} — {{ $contract->reference }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ $occurrence->period_key }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage billing contracts')
            @if ($occurrence->state === 'pending')
                <form method="POST" action="{{ route('billing-contracts.generate-occurrence', [$contract->id, $occurrence->id]) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Generate invoice') }}</button>
                </form>
            @endif
            @if ($occurrence->state === 'invoiced' && $occurrence->invoice_id)
                <form method="POST" action="{{ route('billing-contracts.resend-occurrence', [$contract->id, $occurrence->id]) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-secondary" type="submit">{{ __('Resend invoice email') }}</button>
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
                        <dt class="col-sm-4">{{ __('Contract') }}</dt>
                        <dd class="col-sm-8"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }} — {{ $contract->title }}</a></dd>

                        <dt class="col-sm-4">{{ __('Period') }}</dt>
                        <dd class="col-sm-8">{{ $occurrence->period_key }}</dd>

                        <dt class="col-sm-4">{{ __('Coverage') }}</dt>
                        <dd class="col-sm-8">{{ $occurrence->period_start->format('Y-m-d') }} → {{ $occurrence->coverageEndInclusive() }}</dd>

                        <dt class="col-sm-4">{{ __('Billing date') }}</dt>
                        <dd class="col-sm-8">{{ $occurrence->billing_date->format('Y-m-d') }}</dd>

                        <dt class="col-sm-4">{{ __('Due date') }}</dt>
                        <dd class="col-sm-8">{{ $occurrence->due_date->format('Y-m-d') }}</dd>

                        <dt class="col-sm-4">{{ __('Amount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</dd>

                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($occurrence->state) }}{{ $occurrence->skip_reason ? ' — ' . $occurrence->skip_reason : '' }}</dd>

                        <dt class="col-sm-4">{{ __('Invoice') }}</dt>
                        <dd class="col-sm-8">
                            @if ($occurrence->invoice)
                                <a href="{{ route('invoice.show', \Crypt::encrypt($occurrence->invoice_id)) }}">{{ \Auth::user()->invoiceNumberFormat($occurrence->invoice->invoice_id) }}</a>
                                — {{ __('issued') }} {{ $occurrence->invoice->issue_date }}, {{ __('due') }} {{ $occurrence->invoice->due_date }}, {{ __('total') }} {{ number_format((float) $occurrence->invoice->getTotal(), 2) }}, {{ __('balance due') }} {{ number_format((float) $occurrence->invoice->getDue(), 2) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">{{ __('Email delivery') }}</dt>
                        <dd class="col-sm-8">
                            @if ($attempt)
                                {{ ucfirst($attempt->status) }}
                                @if ($attempt->recipients_to) — {{ $attempt->recipients_to }}@endif
                                @if ($attempt->sent_at) — {{ $attempt->sent_at }}@endif
                                @if ($attempt->attempt_count > 0) — {{ __('attempts') }}: {{ $attempt->attempt_count }}@endif
                                @if ($attempt->next_retry_at) — {{ __('next retry') }}: {{ $attempt->next_retry_at }}@endif
                                @if ($attempt->last_error) <br><small class="text-danger">{{ $attempt->last_error }}</small>@endif
                            @else
                                {{ __('Not attempted.') }}
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6>{{ __('State history') }}</h6>
                    @forelse ($history as $event)
                        <p class="mb-1">
                            <strong>{{ $event->action }}</strong>
                            <small class="text-muted">{{ $event->created_at }}</small>
                            @if ($event->reason)<br><small>{{ $event->reason }}</small>@endif
                            @if (! empty($event->after_values))<br><small>{{ json_encode($event->after_values) }}</small>@endif
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No events recorded for this occurrence.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
