@extends('layouts.admin')
@section('page-title')
    {{ $rule->beneficiary }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-recurring-rules.index') }}">{{ __('Recurring') }}</a></li>
    <li class="breadcrumb-item">{{ $rule->beneficiary }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @if ($rule->state === 'active')
            <form method="POST" action="{{ route('treasury-recurring-rules.pause', $rule->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-warning">{{ __('Pause') }}</button>
            </form>
            <form method="POST" action="{{ route('treasury-recurring-rules.end', $rule->id) }}" class="d-inline" onsubmit="return confirm('{{ __('End this rule? Earlier occurrences are kept.') }}')">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">{{ __('End') }}</button>
            </form>
        @elseif ($rule->state === 'paused')
            <form method="POST" action="{{ route('treasury-recurring-rules.resume', $rule->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-success">{{ __('Resume') }}</button>
            </form>
            <form method="POST" action="{{ route('treasury-recurring-rules.end', $rule->id) }}" class="d-inline" onsubmit="return confirm('{{ __('End this rule? Earlier occurrences are kept.') }}')">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">{{ __('End') }}</button>
            </form>
        @endif
        @if ($rule->state !== 'ended')
            <a href="{{ route('treasury-recurring-rules.edit', $rule->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
        @endif
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">{{ __('Beneficiary') }}</dt>
                        <dd class="col-sm-9">{{ $rule->beneficiary }}</dd>
                        <dt class="col-sm-3">{{ __('Description') }}</dt>
                        <dd class="col-sm-9">{{ $rule->description ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ __('Category') }}</dt>
                        <dd class="col-sm-9">{{ $rule->category ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ __('Direction') }}</dt>
                        <dd class="col-sm-9">{{ ucfirst($rule->direction) }}</dd>
                        <dt class="col-sm-3">{{ __('Amount') }}</dt>
                        <dd class="col-sm-9">{{ number_format((float) $rule->amount, 2) }} {{ $rule->currency }} ({{ $rule->amount_type }})</dd>
                        <dt class="col-sm-3">{{ __('Frequency') }}</dt>
                        <dd class="col-sm-9">{{ ucfirst(str_replace('_', ' ', $rule->frequency)) }}{{ $rule->frequency === 'custom' ? ' — every ' . $rule->custom_interval . ' ' . $rule->custom_unit : '' }}</dd>
                        <dt class="col-sm-3">{{ __('Period') }}</dt>
                        <dd class="col-sm-9">{{ $rule->start_date }} → {{ $rule->end_date ?? __('no end') }}</dd>
                        <dt class="col-sm-3">{{ __('Due day') }}</dt>
                        <dd class="col-sm-9">{{ $rule->due_day ?? __('Start day') }}</dd>
                        <dt class="col-sm-3">{{ __('State') }}</dt>
                        <dd class="col-sm-9">{{ ucfirst($rule->state) }}</dd>
                        <dt class="col-sm-3">{{ __('Version') }}</dt>
                        <dd class="col-sm-9">{{ $rule->version }}</dd>
                        <dt class="col-sm-3">{{ __('Confirmation by another user') }}</dt>
                        <dd class="col-sm-9">{{ $rule->approval_required ? __('Yes') : __('No') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Occurrences') }}</h5>
                </div>
                <div class="card-body">
                    @if ($occurrences->isEmpty())
                        <p class="text-muted">{{ __('No occurrences yet — the daily job generates them up to 90 days ahead.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Scheduled') }}</th>
                                        <th>{{ __('Period') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($occurrences as $occurrence)
                                        <tr>
                                            <td>{{ $occurrence->scheduled_date }}</td>
                                            <td>{{ $occurrence->period_key }}</td>
                                            <td class="text-end">{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</td>
                                            <td>{{ ucfirst($occurrence->state) }}</td>
                                            <td>
                                                @if ($occurrence->state === 'generated')
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.confirm', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Confirm') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.paid', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Mark paid') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.skip', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Skip') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $occurrences->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
