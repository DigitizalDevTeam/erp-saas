@extends('layouts.admin')
@section('page-title')
    {{ __('Bank reconciliation') }} #{{ $session->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a></li>
    <li class="breadcrumb-item">#{{ $session->id }}</li>
@endsection
@section('action-btn')
    <div class="float-end d-flex gap-2">
        {{ Form::open(['route' => ['bank-reconciliation.auto-match', $session], 'method' => 'post', 'class' => 'd-inline']) }}
        @csrf
        <button type="submit" class="btn btn-sm btn-primary">{{ __('Run auto-match') }}</button>
        {{ Form::close() }}
        <a href="{{ route('bank-reconciliation.create') }}" class="btn btn-sm btn-outline-primary">{{ __('New import') }}</a>
    </div>
@endsection
@section('content')
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Statement lines') }}</span>
                    <strong>{{ $stats['lines'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Matched') }}</span>
                    <strong class="text-success">{{ $stats['matched'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Unmatched (statement)') }}</span>
                    <strong class="text-warning">{{ $stats['unmatched_statement'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Unmatched (ERP)') }}</span>
                    <strong class="text-danger">{{ $stats['unmatched_erp'] }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('File') }}:</strong> {{ $session->original_filename }}</p>
                    <p class="mb-1"><strong>{{ __('Bank') }}:</strong>
                        {{ $session->bankAccount ? $session->bankAccount->bank_name . ' — ' . $session->bankAccount->holder_name : '-' }}
                    </p>
                    <p class="mb-0"><strong>{{ __('Preset') }}:</strong>
                        {{ config('bank_reconciliation.presets.' . $session->preset_key . '.label', $session->preset_key) }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Matched') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Statement date') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('ERP date') }}</th>
                                    <th>{{ __('ERP amount') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Match') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($matched as $line)
                                    @php $m = $line->reconciliationMatch; $tx = $m ? $m->erpTransaction : null; @endphp
                                    <tr>
                                        <td>{{ $line->txn_date ? $line->txn_date->format('Y-m-d') : '—' }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($line->description, 60) }}</td>
                                        <td>{{ $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</td>
                                        <td>{{ $tx ? $tx->date : '—' }}</td>
                                        <td>{{ $tx ? number_format((float) $tx->amount, 2) : '—' }}</td>
                                        <td>{{ $tx ? $tx->type : '—' }}</td>
                                        <td><span class="badge bg-{{ $m && $m->match_type === 'manual' ? 'info' : 'secondary' }}">{{ $m->match_type ?? '' }}</span></td>
                                        <td class="text-end">
                                            {{ Form::open(['route' => ['bank-reconciliation.unmatch', $session], 'method' => 'post', 'class' => 'd-inline']) }}
                                            @csrf
                                            {{ Form::hidden('statement_line_id', $line->id) }}
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Unmatch') }}</button>
                                            {{ Form::close() }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">{{ __('No matches yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Unmatched statement lines') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Link ERP transaction') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($unmatchedStatement as $line)
                                    <tr>
                                        <td>{{ $line->txn_date ? $line->txn_date->format('Y-m-d') : '—' }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($line->description, 80) }}</td>
                                        <td>{{ $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</td>
                                        <td>
                                            {{ Form::open(['route' => ['bank-reconciliation.manual-match', $session], 'method' => 'post', 'class' => 'row g-2 align-items-center']) }}
                                            @csrf
                                            {{ Form::hidden('statement_line_id', $line->id) }}
                                            <div class="col-auto">
                                                @php
                                                    $txOpts = $unmatchedErp
                                                        ->mapWithKeys(function ($t) {
                                                            return [
                                                                $t->id =>
                                                                    $t->date .
                                                                    ' | ' .
                                                                    number_format((float) $t->amount, 2) .
                                                                    ' | ' .
                                                                    ($t->type ?? '') .
                                                                    ' | #' .
                                                                    $t->id,
                                                            ];
                                                        })
                                                        ->all();
                                                @endphp
                                                {{ Form::select('transaction_id', ['' => __('— Select —')] + $txOpts, null, ['class' => 'form-select form-select-sm']) }}
                                            </div>
                                            <div class="col-auto">
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Link') }}</button>
                                            </div>
                                            {{ Form::close() }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">{{ __('None') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('ERP transactions not matched (same account & window)') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($unmatchedErp as $t)
                                    <tr>
                                        <td>{{ $t->id }}</td>
                                        <td>{{ $t->date }}</td>
                                        <td>{{ number_format((float) $t->amount, 2) }}</td>
                                        <td>{{ $t->type }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($t->description, 80) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">{{ __('None in range or all matched.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
